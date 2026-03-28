<?php

/**
 * Demonstrates how assigning a static property by reference (=&) in PHP
 * BREAKS the shared slot by creating a new independent binding on the subclass.
 *
 * In TelemetryScrape, the parent does:
 *   self::$CFG = &Telemetry::$CFG;
 *
 * This rebinds TelemetryScrape::$CFG to point at Telemetry::$CFG.
 * BUT — it also silently creates a SEPARATE slot for TelemetryScrape,
 * detaching it from its subclasses, which still hold the old null slot.
 *
 * The fix: subclasses must also do self::$CFG = &TelemetryScrape::$CFG
 * to bind themselves to the same slot the parent rebound to.
 */

class External {
	static $value = "I am External";
}

class Base {
	static $value = null;

	static function bindToExternal() {
		// This rebinds Base's slot to External's slot.
		// But subclasses already have their own slot pointing to the old null!
		self::$value = &External::$value;
		echo "Base::bindToExternal() done. Base::\$value = " . var_export(Base::$value, true) . "\n";
	}
}

class ChildA extends Base {
	// Does nothing — relies on sharing Base's slot
}

class ChildB extends Base {
	// Re-binds its own slot to Base's (now-rebound) slot
	static function bindToBase() {
		self::$value = &Base::$value;
	}
}

// First, access ChildA and ChildB — this forces PHP to materialise their slots
// as a copy of Base's current value (null), BEFORE Base rebinds its own slot.
echo "Initial (before any binding):\n";
echo "  Base::\$value   = " . var_export(Base::$value,   true) . "\n";
echo "  ChildA::\$value = " . var_export(ChildA::$value, true) . "\n";
echo "  ChildB::\$value = " . var_export(ChildB::$value, true) . "\n\n";

// Regular assignment — all subclasses share the slot and see the value
Base::$value = "set normally on Base";
echo "After normal assignment Base::\$value = 'set normally on Base':\n";
echo "  Base::\$value   = " . var_export(Base::$value,   true) . "\n";
echo "  ChildA::\$value = " . var_export(ChildA::$value, true) . " <-- inherited correctly\n";
echo "  ChildB::\$value = " . var_export(ChildB::$value, true) . " <-- inherited correctly\n\n";

// Now Base rebinds its slot to External
Base::bindToExternal();
echo "\nAfter Base::bindToExternal():\n";
echo "  External::\$value = " . var_export(External::$value, true) . "\n";
echo "  Base::\$value     = " . var_export(Base::$value,     true) . "\n";
echo "  ChildA::\$value   = " . var_export(ChildA::$value,   true) . " <-- detached! still has old value\n";
echo "  ChildB::\$value   = " . var_export(ChildB::$value,   true) . " <-- detached! still has old value\n\n";

// ChildB explicitly re-binds itself to Base's slot
ChildB::bindToBase();
echo "After ChildB::bindToBase():\n";
echo "  ChildB::\$value   = " . var_export(ChildB::$value,   true) . " <-- now tracks Base/External\n\n";

// Mutate External to prove ChildB tracks it, ChildA still does not
External::$value = "External mutated";
echo "After mutating External::\$value:\n";
echo "  External::\$value = " . var_export(External::$value, true) . "\n";
echo "  Base::\$value     = " . var_export(Base::$value,     true) . "\n";
echo "  ChildA::\$value   = " . var_export(ChildA::$value,   true) . " <-- still detached old value\n";
echo "  ChildB::\$value   = " . var_export(ChildB::$value,   true) . " <-- tracks the chain\n";
