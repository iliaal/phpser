--TEST--
phpser: __sleep warnings print NUL-containing names in full on PHP 8.6+, as native serialize() does
--EXTENSIONS--
phpser
--SKIPIF--
<?php if (PHP_VERSION_ID < 80600) die("skip PHP 8.6+ only; see 165-sleep-name-parity.phpt"); ?>
--FILE--
<?php require __DIR__ . '/165-sleep-name-parity.inc'; ?>
--EXPECT--
parent private: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "secret" returned as member variable from __sleep() but does not exist
parent private (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "secret" returned as member variable from __sleep() but does not exist
parent private mangled: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:19:"\0SleepParent\0secret";s:1:"s";s:3:"pub";s:1:"u";}
parent private mangled (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:19:"\0SleepParent\0secret";s:1:"s";s:3:"pub";s:1:"u";}
shadowed private: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:18:"\0SleepChild\0shadow";s:12:"child-shadow";s:19:"\0SleepParent\0shadow";s:13:"parent-shadow";}
shadowed private (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:18:"\0SleepChild\0shadow";s:12:"child-shadow";s:19:"\0SleepParent\0shadow";s:13:"parent-shadow";}
protected: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:7:"\0*\0prot";s:1:"p";}
  warn "\0*\0prot" is returned from __sleep() multiple times
protected (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:7:"\0*\0prot";s:1:"p";}
  warn "\0*\0prot" is returned from __sleep() multiple times
missing: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:15:"\0SleepChild\0own";s:1:"o";}
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "also_nope" returned as member variable from __sleep() but does not exist
missing (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:15:"\0SleepChild\0own";s:1:"o";}
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "also_nope" returned as member variable from __sleep() but does not exist
missing with NUL: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "\0SleepParent\0nope" returned as member variable from __sleep() but does not exist
  warn "no\0pe" returned as member variable from __sleep() but does not exist
missing with NUL (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "\0SleepParent\0nope" returned as member variable from __sleep() but does not exist
  warn "no\0pe" returned as member variable from __sleep() but does not exist
static: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "stat" returned as member variable from __sleep() but does not exist
static (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "stat" returned as member variable from __sleep() but does not exist
duplicate: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"pub";s:1:"u";s:15:"\0SleepChild\0own";s:1:"o";}
  warn "pub" is returned from __sleep() multiple times
  warn "own" is returned from __sleep() multiple times
duplicate (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"pub";s:1:"u";s:15:"\0SleepChild\0own";s:1:"o";}
  warn "pub" is returned from __sleep() multiple times
  warn "own" is returned from __sleep() multiple times
unset untyped: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "untyped" returned as member variable from __sleep() but does not exist
unset untyped (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "untyped" returned as member variable from __sleep() but does not exist
uninitialized typed: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:8:"typedSet";i:3;}
uninitialized typed (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:8:"typedSet";i:3;}
dynamic: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"dyn";s:1:"d";s:3:"pub";s:1:"u";}
dynamic (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "dyn" returned as member variable from __sleep() but does not exist
integer name: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"123";s:3:"num";s:3:"pub";s:1:"u";}
  warn SleepChild::__sleep() should return an array only containing the names of instance-variables to serialize
integer name (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn SleepChild::__sleep() should return an array only containing the names of instance-variables to serialize
  warn "123" returned as member variable from __sleep() but does not exist
empty: bytes SAME, warnings SAME
  native O:10:"SleepChild":0:{}
empty (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":0:{}
no properties table: bytes SAME, warnings SAME
  native O:15:"SleepPlainChild":2:{s:7:"\0*\0prot";s:1:"p";s:3:"pub";s:1:"u";}
  warn "secret" returned as member variable from __sleep() but does not exist
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "pub" is returned from __sleep() multiple times
mangled dynamic beside protected: bytes SAME, warnings SAME
  native O:14:"SleepProtChild":1:{s:17:"\0SleepProtChild\0p";s:11:"dyn-mangled";}
nested: bytes SAME, warnings SAME
  native a:2:{i:0;O:15:"SleepPlainChild":2:{s:7:"\0*\0prot";s:1:"p";s:3:"pub";s:1:"u";}i:1;O:15:"SleepPlainChild":2:{s:7:"\0*\0prot";s:1:"p";s:3:"pub";s:1:"u";}}
  warn "secret" returned as member variable from __sleep() but does not exist
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "pub" is returned from __sleep() multiple times
  warn "secret" returned as member variable from __sleep() but does not exist
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "pub" is returned from __sleep() multiple times
