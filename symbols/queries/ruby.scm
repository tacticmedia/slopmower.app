; Ruby. A capture named after a SymbolKind value marks the declaration, @name its name, and @parent the scope of a
; compact name such as `class A::B`. When several patterns capture one name, the first pattern wins, so a `def` at the
; top level is a function.

(module
  name: (scope_resolution
    scope: (_) @parent
    name: (constant) @name)) @module

(module
  name: [
    (constant) @name
    (scope_resolution
      name: (constant) @name)
  ]) @module

(class
  name: (scope_resolution
    scope: (_) @parent
    name: (constant) @name)) @class

(class
  name: [
    (constant) @name
    (scope_resolution
      name: (constant) @name)
  ]) @class

(program
  (method
    name: (_) @name) @function)

[
  (method
    name: (_) @name)
  (singleton_method
    name: (_) @name)
] @method

(assignment
  left: (constant) @name) @constant
