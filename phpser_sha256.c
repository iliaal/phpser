/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026, Ilia Alshanetsky                            |
  | Copyright (c) 2025-2026, Advanced Internet Designs Inc.              |
  +----------------------------------------------------------------------+
  | This source file is subject to the BSD 3-Clause license that is      |
  | bundled with this package in the file LICENSE.                       |
  +----------------------------------------------------------------------+
  | Author: Ilia Alshanetsky <ilia@ilia.ws>                              |
  +----------------------------------------------------------------------+
*/

/* -------------------------------------------------------------------------
 * SHA-256 block compression for the signed-payload HMAC.
 *
 * ext/hash accelerates SHA-256 only on x86 and only from PHP 8.4 (SSE2 /
 * SHA-NI); PHP 8.2/8.3 and every aarch64 build run portable C, roughly 24
 * cycles/byte on Neoverse-N1. This file adds an ARMv8 Crypto Extensions path
 * and a multi-block SHA-NI path (ext/hash's 8.4 SHA-NI re-dispatches and
 * reshuffles the state on every block), and falls back to ext/hash for
 * everything else, including MSVC builds.
 * ------------------------------------------------------------------------- */

#include "phpser_int.h"
#include "phpser_sha256.h"
#include "ext/hash/php_hash.h"
#include "ext/hash/php_hash_sha.h"

#include <string.h>

/* The intrinsics are compiled per function via a target attribute so the
 * rest of the extension keeps the distribution's baseline ISA. GCC's
 * arm_neon.h exposes the SHA2 intrinsics under its own target pragma; older
 * clang releases gate them on __ARM_FEATURE_SHA2, so clang only takes the
 * path when the baseline already includes SHA2 (Apple arm64). */
#if defined(__aarch64__) && defined(__AARCH64EL__)
# if defined(__ARM_FEATURE_SHA2) || defined(__ARM_FEATURE_CRYPTO)
#  define PHPSER_SHA256_ARMV8 1
#  define PHPSER_SHA256_ARMV8_ALWAYS 1
#  define PHPSER_ARMV8_TARGET
# elif defined(__GNUC__) && !defined(__clang__) && __GNUC__ >= 6 && defined(__linux__)
#  define PHPSER_SHA256_ARMV8 1
#  define PHPSER_ARMV8_TARGET __attribute__((target("+crypto")))
# endif
#endif

/* MSVC is left on the ext/hash fallback: SHA-NI there on PHP 8.4+, portable
 * C on 8.2 and 8.3. */
#if (defined(__x86_64__) || defined(__i386__)) && (defined(__GNUC__) || defined(__clang__))
# define PHPSER_SHA256_SHANI 1
# define PHPSER_SHANI_TARGET __attribute__((target("sha,ssse3,sse4.1")))
# include <immintrin.h>
# include <cpuid.h>
#endif

#if defined(PHPSER_SHA256_ARMV8) || defined(PHPSER_SHA256_SHANI)
# define PHPSER_SHA256_HW 1
#endif

#ifdef PHPSER_SHA256_ARMV8
# include <arm_neon.h>
# ifndef PHPSER_SHA256_ARMV8_ALWAYS
#  include <sys/auxv.h>
#  ifndef HWCAP_SHA2
#   define HWCAP_SHA2 (1UL << 6)
#  endif
# endif
#endif

const uint32_t phpser_sha256_iv[8] = {
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
};

/* ext/hash exposes no block-level entry point. Seeding a context with the
 * running state and feeding whole blocks makes PHP_SHA256Update compress
 * each one without buffering a tail; the bit counter is never read. */
static void sha256_blocks_exthash(uint32_t state[8], const unsigned char *data,
                                  size_t nblocks)
{
    PHP_SHA256_CTX ctx;
    memcpy(ctx.state, state, sizeof(ctx.state));
    ctx.count[0] = ctx.count[1] = 0;
    PHP_SHA256Update(&ctx, data, nblocks * 64);
    memcpy(state, ctx.state, sizeof(ctx.state));
    ZEND_SECURE_ZERO(&ctx, sizeof(ctx));
}

#ifdef PHPSER_SHA256_HW
static const uint32_t sha256_k[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
};
#endif

#ifdef PHPSER_SHA256_ARMV8

/* Four rounds on schedule words `a` (W[4g..4g+3]). While g < 12, `a` is
 * replaced by W[4g+16..4g+19], computed from the next three quads b, c, d. */
#define ARMV8_ROUND4(g, a, b, c, d) do { \
        uint32x4_t wk_ = vaddq_u32(a, vld1q_u32(&sha256_k[4 * (g)])); \
        uint32x4_t abcd_ = st0; \
        if ((g) < 12) a = vsha256su0q_u32(a, b); \
        st0 = vsha256hq_u32(st0, st1, wk_); \
        st1 = vsha256h2q_u32(st1, abcd_, wk_); \
        if ((g) < 12) a = vsha256su1q_u32(a, c, d); \
    } while (0)

PHPSER_ARMV8_TARGET
static void sha256_blocks_armv8(uint32_t state[8], const unsigned char *data,
                                size_t nblocks)
{
    uint32x4_t st0 = vld1q_u32(&state[0]);
    uint32x4_t st1 = vld1q_u32(&state[4]);

    while (nblocks--) {
        uint32x4_t save0 = st0, save1 = st1;
        uint32x4_t m0 = vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(data)));
        uint32x4_t m1 = vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(data + 16)));
        uint32x4_t m2 = vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(data + 32)));
        uint32x4_t m3 = vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(data + 48)));

        ARMV8_ROUND4( 0, m0, m1, m2, m3);
        ARMV8_ROUND4( 1, m1, m2, m3, m0);
        ARMV8_ROUND4( 2, m2, m3, m0, m1);
        ARMV8_ROUND4( 3, m3, m0, m1, m2);
        ARMV8_ROUND4( 4, m0, m1, m2, m3);
        ARMV8_ROUND4( 5, m1, m2, m3, m0);
        ARMV8_ROUND4( 6, m2, m3, m0, m1);
        ARMV8_ROUND4( 7, m3, m0, m1, m2);
        ARMV8_ROUND4( 8, m0, m1, m2, m3);
        ARMV8_ROUND4( 9, m1, m2, m3, m0);
        ARMV8_ROUND4(10, m2, m3, m0, m1);
        ARMV8_ROUND4(11, m3, m0, m1, m2);
        ARMV8_ROUND4(12, m0, m1, m2, m3);
        ARMV8_ROUND4(13, m1, m2, m3, m0);
        ARMV8_ROUND4(14, m2, m3, m0, m1);
        ARMV8_ROUND4(15, m3, m0, m1, m2);

        st0 = vaddq_u32(st0, save0);
        st1 = vaddq_u32(st1, save1);
        data += 64;
    }

    vst1q_u32(&state[0], st0);
    vst1q_u32(&state[4], st1);
}
#undef ARMV8_ROUND4

static bool sha256_cpu_has_armv8(void)
{
# ifdef PHPSER_SHA256_ARMV8_ALWAYS
    return true;
# else
    return (getauxval(AT_HWCAP) & HWCAP_SHA2) != 0;
# endif
}
#endif /* PHPSER_SHA256_ARMV8 */

#ifdef PHPSER_SHA256_SHANI
/* Four rounds on schedule words `cur` (W[4g..4g+3]). For 3 <= g <= 14 it
 * finishes W[4g+4..4g+7] in `next`; for 1 <= g <= 12 it starts the sigma0
 * half of W[4g+12..4g+15] in `prev`. */
#define SHANI_ROUND4(g, cur, next, prev) do { \
        __m128i wk_ = _mm_add_epi32(cur, \
            _mm_loadu_si128((const __m128i *)&sha256_k[4 * (g)])); \
        st1 = _mm_sha256rnds2_epu32(st1, st0, wk_); \
        if ((g) >= 3 && (g) <= 14) { \
            next = _mm_add_epi32(next, _mm_alignr_epi8(cur, prev, 4)); \
            next = _mm_sha256msg2_epu32(next, cur); \
        } \
        wk_ = _mm_shuffle_epi32(wk_, 0x0E); \
        st0 = _mm_sha256rnds2_epu32(st0, st1, wk_); \
        if ((g) >= 1 && (g) <= 12) prev = _mm_sha256msg1_epu32(prev, cur); \
    } while (0)

PHPSER_SHANI_TARGET
static void sha256_blocks_shani(uint32_t state[8], const unsigned char *data,
                                size_t nblocks)
{
    const __m128i bswap = _mm_set_epi64x(0x0c0d0e0f08090a0bLL, 0x0405060700010203LL);
    /* SHA-NI keeps the state as ABEF / CDGH. */
    __m128i tmp = _mm_shuffle_epi32(_mm_loadu_si128((const __m128i *)&state[0]), 0xB1);
    __m128i st1 = _mm_shuffle_epi32(_mm_loadu_si128((const __m128i *)&state[4]), 0x1B);
    __m128i st0 = _mm_alignr_epi8(tmp, st1, 8);
    st1 = _mm_blend_epi16(st1, tmp, 0xF0);

    while (nblocks--) {
        __m128i save0 = st0, save1 = st1;
        __m128i m0 = _mm_shuffle_epi8(_mm_loadu_si128((const __m128i *)(data)), bswap);
        __m128i m1 = _mm_shuffle_epi8(_mm_loadu_si128((const __m128i *)(data + 16)), bswap);
        __m128i m2 = _mm_shuffle_epi8(_mm_loadu_si128((const __m128i *)(data + 32)), bswap);
        __m128i m3 = _mm_shuffle_epi8(_mm_loadu_si128((const __m128i *)(data + 48)), bswap);

        SHANI_ROUND4( 0, m0, m1, m3);
        SHANI_ROUND4( 1, m1, m2, m0);
        SHANI_ROUND4( 2, m2, m3, m1);
        SHANI_ROUND4( 3, m3, m0, m2);
        SHANI_ROUND4( 4, m0, m1, m3);
        SHANI_ROUND4( 5, m1, m2, m0);
        SHANI_ROUND4( 6, m2, m3, m1);
        SHANI_ROUND4( 7, m3, m0, m2);
        SHANI_ROUND4( 8, m0, m1, m3);
        SHANI_ROUND4( 9, m1, m2, m0);
        SHANI_ROUND4(10, m2, m3, m1);
        SHANI_ROUND4(11, m3, m0, m2);
        SHANI_ROUND4(12, m0, m1, m3);
        SHANI_ROUND4(13, m1, m2, m0);
        SHANI_ROUND4(14, m2, m3, m1);
        SHANI_ROUND4(15, m3, m0, m2);

        st0 = _mm_add_epi32(st0, save0);
        st1 = _mm_add_epi32(st1, save1);
        data += 64;
    }

    tmp = _mm_shuffle_epi32(st0, 0x1B);
    st1 = _mm_shuffle_epi32(st1, 0xB1);
    st0 = _mm_blend_epi16(tmp, st1, 0xF0);
    st1 = _mm_alignr_epi8(st1, tmp, 8);
    _mm_storeu_si128((__m128i *)&state[0], st0);
    _mm_storeu_si128((__m128i *)&state[4], st1);
}
#undef SHANI_ROUND4

/* Queried directly: ZEND_CPU_FEATURE_SHA exists only from PHP 8.4, and
 * __builtin_cpu_supports needs libgcc's CPU model, which some toolchains
 * (zig cc, musl) lack. SHA-NI uses only XMM state, so no XCR0 check. */
static bool sha256_cpu_has_shani(void)
{
    unsigned int eax, ebx, ecx, edx;
    if (!__get_cpuid(1, &eax, &ebx, &ecx, &edx)) return false;
    if (!(ecx & (1u << 9)) || !(ecx & (1u << 19))) return false;   /* SSSE3, SSE4.1 */
    if (__get_cpuid_max(0, NULL) < 7) return false;
    __cpuid_count(7, 0, eax, ebx, ecx, edx);
    return (ebx & (1u << 29)) != 0;                                  /* SHA */
}
#endif /* PHPSER_SHA256_SHANI */

#ifdef PHPSER_SHA256_HW
/* Chains 1 + 3 blocks through the candidate so both the single-block path
 * and the multi-block loop, plus the state reload between calls, are
 * compared against ext/hash's four-block result. */
static bool sha256_candidate_ok(phpser_sha256_blocks_fn fn)
{
    unsigned char data[256];
    uint32_t want[8], got[8];
    for (size_t i = 0; i < sizeof(data); i++) {
        data[i] = (unsigned char)(i * 131 + 7);
    }
    memcpy(want, phpser_sha256_iv, sizeof(want));
    memcpy(got, phpser_sha256_iv, sizeof(got));
    sha256_blocks_exthash(want, data, 4);
    fn(got, data, 1);
    fn(got, data + 64, 3);
    return memcmp(want, got, sizeof(want)) == 0;
}

/* Not ZEND_ASSERT: release builds expand it to ZEND_ASSUME, which lets the
 * compiler delete the fallback and trust a failed self-test. */
static bool sha256_hw_accepted(phpser_sha256_blocks_fn fn, const char *name)
{
    if (EXPECTED(sha256_candidate_ok(fn))) {
        return true;
    }
#if ZEND_DEBUG
    zend_error_noreturn(E_CORE_ERROR,
        "phpser: %s SHA-256 disagrees with ext/hash", name);
#else
    (void)name;
#endif
    return false;
}
#endif

phpser_sha256_blocks_fn phpser_sha256_select(const char **name)
{
#ifdef PHPSER_SHA256_ARMV8
    if (sha256_cpu_has_armv8() && sha256_hw_accepted(sha256_blocks_armv8, "ARMv8")) {
        *name = "armv8";
        return sha256_blocks_armv8;
    }
#endif
#ifdef PHPSER_SHA256_SHANI
    if (sha256_cpu_has_shani() && sha256_hw_accepted(sha256_blocks_shani, "SHA-NI")) {
        *name = "sha-ni";
        return sha256_blocks_shani;
    }
#endif
    *name = "ext/hash";
    return sha256_blocks_exthash;
}

const char *phpser_sha256_backend(void)
{
    const char *name;
    phpser_sha256_select(&name);
    return name;
}
