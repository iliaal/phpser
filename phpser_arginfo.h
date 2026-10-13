/* This is a generated file, edit phpser.stub.php instead.
 * Stub hash: 1906350e2f15801988385b98fdd56ae9a1c915e5 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_serialize, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_unserialize, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, str, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_serialize_signed, 0, 2, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_unserialize_signed, 0, 2, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_serialize_keyed, 0, 2, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, context, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phpser_unserialize_keyed, 0, 2, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, context, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_FUNCTION(phpser_serialize);
ZEND_FUNCTION(phpser_unserialize);
ZEND_FUNCTION(phpser_serialize_signed);
ZEND_FUNCTION(phpser_unserialize_signed);
ZEND_FUNCTION(phpser_serialize_keyed);
ZEND_FUNCTION(phpser_unserialize_keyed);

static const zend_function_entry ext_functions[] = {
	ZEND_FE(phpser_serialize, arginfo_phpser_serialize)
	ZEND_FE(phpser_unserialize, arginfo_phpser_unserialize)
	ZEND_FE(phpser_serialize_signed, arginfo_phpser_serialize_signed)
	ZEND_FE(phpser_unserialize_signed, arginfo_phpser_unserialize_signed)
	ZEND_FE(phpser_serialize_keyed, arginfo_phpser_serialize_keyed)
	ZEND_FE(phpser_unserialize_keyed, arginfo_phpser_unserialize_keyed)
	ZEND_FE_END
};
