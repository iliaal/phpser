# Build against an in-tree PHP checkout; override PHP_SRC to select the tree.

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

# Match PHP's shared-extension flags, including its per-extension TLS cache.
CFLAGS := \
  -O2 -g -fPIC -fvisibility=hidden \
  -Wall -Wextra -Wno-unused-parameter -Wno-sign-compare \
  -DZEND_COMPILE_DL_EXT=1 \
  -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 \
  $(INCLUDES)

LDFLAGS := -shared

SRCS := phpser.c phpser_hmac.c phpser_session.c phpser_module.c
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
	rm -rf tests/*.diff tests/*.out tests/*.exp tests/*.log tests/*.sh

# TEST_PHP_ARGS loads the extension in child processes; parent -d flags do not.
.PHONY: clean test

test: $(TARGET)
	TEST_PHP_EXECUTABLE=$(PHP_SRC)/sapi/cli/php \
	TEST_PHP_ARGS="-d extension=$(CURDIR)/$(TARGET)" \
	$(PHP_SRC)/sapi/cli/php \
	  $(PHP_SRC)/run-tests.php \
	  -P -q --show-diff \
	  $(CURDIR)/tests
