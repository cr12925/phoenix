<?php

// Screen

define('VCLS', chr(0x0C));

define('ESC', "\x1b");

// Text Colours
//define('VTRED', ESC."A");
//define('VTGRN', ESC."B");
//define('VTYEL', ESC."C");
//define('VTBLU', ESC."D");
//define('VTMAG', ESC."E");
//define('VTCYN', ESC."F");
//define('VTWHT', ESC."G");
define('VTRED', chr(0x81));
define('VTGRN', chr(0x82));
define('VTYEL', chr(0x83));
define('VTBLU', chr(0x84));
define('VTMAG', chr(0x85));
define('VTCYN', chr(0x86));
define('VTWHT', chr(0x87));

// Graphics colours
define('VGRED', chr(64+ord("Q")));
define('VGGRN', chr(64+ord("R")));
define('VGYEL', chr(64+ord("S")));
define('VGBLU', chr(64+ord("T")));
define('VGMAG', chr(64+ord("U")));
define('VGCYN', chr(64+ord("V")));
define('VGWHT', chr(64+ord("W")));
//define('VGGRN', ESC."R");
//define('VGYEL', ESC."S");
//define('VGBLU', ESC."T");
//define('VGMAG', ESC."U");
//define('VGCYN', ESC."V");
//define('VGWHT', ESC."W");

// Background
//define('VBKGBLACK', ESC."\\");
//define('VBKGNEW', ESC."]");
define('VBKGBLACK', chr(64+ord("\\")));
define('VBKGNEW', chr(64+ord("]")));

// Navigation
define('VNLEFT', chr(8));
define('VNRIGHT', chr(9));
define('VNDOWN', chr(10));
define('VNUP', chr(11));
define('VNLINESTART', chr(13));
define('VNCR', chr(13));
define('VNHOME', chr(30));
define('VNMOVE', chr(31));
define('VNDELETE', chr(127));
define('VSCROLL', chr(15));
define('VPAGED', chr(14));
define('VCURSORON', chr(17));
define('VCURSOROFF', chr(20));

// misc
//define('VFLASH', ESC."H");
//define('VSTEADY', ESC."I");
//define('VNHEIGHT', ESC."L");
//define('VDHEIGHT', ESC."M");
//define('VCONCEAL', ESC."X");
//define('VGCONTIG', ESC."Y");
//define('VGSEP', ESC."Z");
//define('VGHOLD', ESC."^");
//define('VGREL', ESC."_");

define('VFLASH', chr(64+ord("H")));
define('VSTEADY', chr(64+ord("I")));
define('VNHEIGHT', chr(64+ord("L")));
define('VDHEIGHT', chr(64+ord("M")));
define('VCONCEAL', chr(64+ord("X")));
define('VGCONTIG', chr(64+ord("Y")));
define('VGSEP', chr(64+ord("Z")));
define('VGHOLD', chr(64+ord("^")));
define('VGREL', chr(64+ord("_")));

define('VBLOCK', chr(127));

// key input

define('VDKEYALPHA', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
define('VDKEYNUMERIC', '0123456789');
define('VDKEYSPACE', ' ');
define('VDKEYALPHANUMERIC', VDKEYALPHA.VDKEYNUMERIC);
define('VDKEYSTAR', chr(0x2a));
define('VDKEYENTER', chr(0x0d)."_");
define('VDKEYBACKSPACE', chr(0x7f));
define('VDKEYPUNCT', "!\"$%&'()+,-./:;<=>?@[\\]{}|~");
define('VDKEYCOLOUR', VTRED.VTGRN.VTYEL.VTBLU.VTMAG.VTCYN.VTWHT);
define('VDKEYGENERAL', VDKEYALPHANUMERIC.VDKEYSTAR.VDKEYENTER.VDKEYBACKSPACE.VDKEYPUNCT.VDKEYSPACE.VDKEYCOLOUR);

?>
