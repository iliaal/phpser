/*
 * phpser — public header.
 *
 * Re-exported via PHP_INSTALL_HEADERS so other extensions can call into
 * the encode/decode paths without going through the PHP function dispatch.
 */
#ifndef PHP_PHPSER_H
#define PHP_PHPSER_H

#include "php.h"

#define PHP_PHPSER_VERSION "0.1.0"
#define PHP_PHPSER_EXTNAME "phpser"

extern zend_module_entry phpser_module_entry;
#define phpext_phpser_ptr &phpser_module_entry

#endif /* PHP_PHPSER_H */
