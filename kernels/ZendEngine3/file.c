
/*
  +------------------------------------------------------------------------+
  | Zephir Language                                                        |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2015 Zephir Team (http://www.zephir-lang.com)       |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file docs/LICENSE.txt.                        |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@zephir-lang.com so we can send you a copy immediately.      |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@zephir-lang.com>                     |
  |          Eduar Carvajal <eduar@zephir-lang.com>                        |
  |          Vladimir Kolesnikov <vladimir@extrememember.com>              |
  +------------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ext.h"
#include "php_main.h"
#include "main/php_streams.h"
#include "ext/standard/file.h"
#include "ext/standard/php_smart_string.h"
#include "ext/standard/php_filestat.h"
#include "ext/standard/php_string.h"

#include "kernel/main.h"
#include "kernel/memory.h"
#include "kernel/concat.h"
#include "kernel/operators.h"
#include "kernel/file.h"

#include "Zend/zend_exceptions.h"
#include "Zend/zend_interfaces.h"

#define PHP_STREAM_TO_ZVAL(stream, arg) \
	php_stream_from_zval_no_verify(stream, arg); \
	if (stream == NULL) {   \
		if (return_value) { \
			RETURN_FALSE;   \
		} else { \
			return; \
		} \
	}

/**
 * Checks if a file exist
 *
 */
int zephir_file_exists(zval *filename)
{
	zval return_value;

	if (Z_TYPE_P(filename) != IS_STRING) {
		return FAILURE;
	}

	php_stat(Z_STRVAL_P(filename), (php_stat_len) Z_STRLEN_P(filename), FS_EXISTS, &return_value);

	if (Z_TYPE(return_value) != IS_TRUE) {
		return FAILURE;
	}

	return SUCCESS;
}


void zephir_fwrite(zval *return_value, zval *stream_zval, zval *data)
{

	int num_bytes;
	php_stream *stream;

	if (Z_TYPE_P(stream_zval) != IS_RESOURCE) {
		php_error_docref(NULL, E_WARNING, "Invalid arguments supplied for zephir_fwrite()");
		if (return_value) {
			RETVAL_FALSE;
		} else {
			return;
		}
	}

	if (Z_TYPE_P(data) != IS_STRING) {
		/* @todo convert data to string */
		php_error_docref(NULL, E_WARNING, "Invalid arguments supplied for zephir_fwrite()");
		if (return_value) {
			RETVAL_FALSE;
		} else {
			return;
		}
	}

	if (!Z_STRLEN_P(data)) {
		if (return_value) {
			RETURN_LONG(0);
		} else {
			return;
		}
	}

	PHP_STREAM_TO_ZVAL(stream, stream_zval);

	num_bytes = php_stream_write(stream, Z_STRVAL_P(data), Z_STRLEN_P(data));
	if (return_value) {
		RETURN_LONG(num_bytes);
	}
}

int zephir_feof(zval *stream_zval)
{

	php_stream *stream;

	if (Z_TYPE_P(stream_zval) != IS_RESOURCE) {
		php_error_docref(NULL, E_WARNING, "Invalid arguments supplied for zephir_feof()");
		return 0;
	}

	php_stream_from_zval_no_verify(stream, stream_zval);
	if (stream == NULL) {
		return 0;
	}

	return php_stream_eof(stream);
}

int zephir_fclose(zval *stream_zval)
{
	php_stream *stream;

	if (Z_TYPE_P(stream_zval) != IS_RESOURCE) {
		php_error_docref(NULL, E_WARNING, "Invalid arguments supplied for zephir_fwrite()");
		return 0;
	}

	php_stream_from_zval_no_verify(stream, stream_zval);
	if (stream == NULL) {
		return 0;
	}

	if ((stream->flags & PHP_STREAM_FLAG_NO_FCLOSE) != 0) {
		php_error_docref(NULL, E_WARNING, "%d is not a valid stream resource", stream->res->handle);
		return 0;
	}

	if (!stream->is_persistent) {
		php_stream_close(stream);
	} else {
		php_stream_pclose(stream);
	}

	return 1;
}
