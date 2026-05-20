# Hand-rolled Makefile for phpser. Targets in-tree PHP at $(PHP_SRC) — avoids
# phpize, which expects an installed PHP build tree at $(prefix)/lib/php/build.
#
# Override PHP_SRC to point at a different in-tree PHP checkout, e.g.
#   make PHP_SRC=$HOME/php-src-8.4
# Default is the opt (release-mode) tree we use for benchmarks.

PHP_SRC      ?= $(HOME)/php-src-8.4-opt
CC           ?= cc
TARGET       := modules/phpser.so

INCLUDES := \
  -I$(PHP_SRC) \
  -I$(PHP_SRC)/main \
  -I$(PHP_SRC)/TSRM \
  -I$(PHP_SRC)/Zend \
  -I$(PHP_SRC)/ext \
  -I$(PHP_SRC)/ext/date/lib

# Match the flags the PHP build system uses for built-in extensions, minus
# the ones that only matter for the static link. -DZEND_ENABLE_STATIC_TSRMLS_CACHE
# is the per-extension TLS shortcut that PHP's build system sets for shared exts.
CFLAGS := \
  -O2 -g -fPIC -fvisibility=hidden \
  -Wall -Wextra -Wno-unused-parameter -Wno-sign-compare \
  -DZEND_COMPILE_DL_EXT=1 \
  -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 \
  $(INCLUDES)

LDFLAGS := -shared

SRCS := phpser.c
OBJS := $(SRCS:.c=.o)

$(TARGET): $(OBJS) | modules
	$(CC) $(LDFLAGS) -o $@ $(OBJS)

modules:
	mkdir -p modules

%.o: %.c
	$(CC) $(CFLAGS) -c -o $@ $<

clean:
	rm -f $(OBJS) $(TARGET)
	rm -rf modules

.PHONY: clean
