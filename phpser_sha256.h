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

/* SHA-256 compression dispatch shared by phpser_sha256.c and phpser_hmac.c.
 * This header is not installed. */
#ifndef PHPSER_SHA256_H
#define PHPSER_SHA256_H

#include "php.h"

/* Compresses `nblocks` consecutive 64-byte blocks into `state` (host-order
 * words a..h). Padding and length encoding are the caller's job. */
typedef void (*phpser_sha256_blocks_fn)(uint32_t state[8],
                                        const unsigned char *data,
                                        size_t nblocks);

extern const uint32_t phpser_sha256_iv[8];

/* Picks the fastest compression the CPU supports and stores its backend name
 * in *name. A hardware candidate is accepted only after it matches ext/hash
 * on a fixed multi-block vector; otherwise the ext/hash-backed compression is
 * returned. Pure apart from the self-test, so each thread may call it
 * independently. */
phpser_sha256_blocks_fn phpser_sha256_select(const char **name);

#endif /* PHPSER_SHA256_H */
