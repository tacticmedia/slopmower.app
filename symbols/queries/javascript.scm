; JavaScript and JSX. A capture named after a SymbolKind value marks the declaration, @name its name. When several
; patterns capture one name, the first pattern wins.

(class_declaration
  name: (identifier) @name) @class

(class_body
  (method_definition
    name: [(property_identifier) (private_property_identifier)] @name) @method)

(class_body
  (field_definition
    property: [(property_identifier) (private_property_identifier)] @name) @property)

[
  (function_declaration
    name: (identifier) @name)
  (generator_function_declaration
    name: (identifier) @name)
] @function

(lexical_declaration
  kind: "const"
  (variable_declarator
    name: (identifier) @name
    value: [(arrow_function) (function_expression) (generator_function)])) @function

(lexical_declaration
  kind: "const"
  (variable_declarator
    name: (identifier) @name)) @constant
