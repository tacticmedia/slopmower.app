; Zig. A capture named after a SymbolKind value marks the declaration, @name its name. When several patterns capture
; one name, the first pattern wins, so a `const` of a container type is that container, and an `fn` at the top level
; is a function.

(variable_declaration
  .
  (identifier) @name
  [
    (struct_declaration)
    (union_declaration)
    (opaque_declaration)
  ]) @struct

(variable_declaration
  .
  (identifier) @name
  (enum_declaration)) @enum

[
  (struct_declaration
    (container_field
      name: (identifier) @name) @property)
  (union_declaration
    (container_field
      name: (identifier) @name) @property)
]

(enum_declaration
  (container_field
    name: (identifier) @name) @enum_case)

(source_file
  (function_declaration
    name: (identifier) @name) @function)

(function_declaration
  name: (identifier) @name) @method

(variable_declaration
  "const"
  .
  (identifier) @name) @constant
