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
