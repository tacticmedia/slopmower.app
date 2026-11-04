; PHP, for the outline of Browser only. The server parses PHP with nikic/php-parser. A capture named after a SymbolKind
; value marks the declaration, @name its name.

(class_declaration
  name: (name) @name) @class

(interface_declaration
  name: (name) @name) @interface

(trait_declaration
  name: (name) @name) @trait

(enum_declaration
  name: (name) @name) @enum

(function_definition
  name: (name) @name) @function

(method_declaration
  name: (name) @name) @method

(property_declaration
  (property_element
    (variable_name
      (name) @name))) @property

(declaration_list
  (const_declaration
    (const_element
      (name) @name)) @class_constant)

(enum_declaration_list
  (const_declaration
    (const_element
      (name) @name)) @class_constant)

(enum_case
  name: (name) @name) @enum_case
