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
 * phpser module entry header.
 *
 * Exports only the zend_module_entry. The encode/decode paths are not a
 * stable C API; use phpser_serialize() / phpser_unserialize() from PHP, or
 * the session.serialize_handler when HAVE_PHP_SESSION.
 */
#ifndef PHP_PHPSER_H
#define PHP_PHPSER_H

#include "php.h"

#define PHP_PHPSER_VERSION "0.6.2"
#define PHP_PHPSER_EXTNAME "phpser"

extern zend_module_entry phpser_module_entry;
#define phpext_phpser_ptr &phpser_module_entry

#endif /* PHP_PHPSER_H */
