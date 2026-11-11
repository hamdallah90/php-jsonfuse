PHP_ARG_ENABLE([jsonfuse],
  [whether to enable JsonFuse support],
  [AS_HELP_STRING([--enable-jsonfuse], [Enable JsonFuse support])],
  [no])

if test "$PHP_JSONFUSE" != "no"; then
  PHP_NEW_EXTENSION([jsonfuse], [src/jsonfuse.c src/jf_plan.c src/jf_runner.c src/jf_stream.c src/jf_parse.c src/jf_writer.c src/jf_errors.c third_party/yyjson/yyjson.c], [$ext_shared],, [-fvisibility=hidden -Dyyjson_api= -DYYJSON_READER_DEPTH_LIMIT=511])
  PHP_ADD_INCLUDE([$ext_srcdir/stubs])
  PHP_ADD_INCLUDE([$ext_srcdir/third_party/yyjson])
  PHP_ADD_BUILD_DIR([$ext_builddir/src])
  PHP_ADD_BUILD_DIR([$ext_builddir/third_party/yyjson])
fi
