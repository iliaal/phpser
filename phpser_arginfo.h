/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: d54adaf02569ba8c1ec5bce92b1d57554e8775d9 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_serialize, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_unserialize, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, str, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(phpser_serialize);
ZEND_FUNCTION(phpser_unserialize);

static const zend_function_entry ext_functions[] = {
	ZEND_FE(phpser_serialize, arginfo_phpser_serialize)
	ZEND_FE(phpser_unserialize, arginfo_phpser_unserialize)
	ZEND_FE_END
};
