; Lua. A capture named after a SymbolKind value marks the declaration, @name its name, and @parent the table of a
; function `M.f` or a method `M:m`.

(function_declaration
  name: (dot_index_expression
    table: (_) @parent
    field: (identifier) @name)) @function

(function_declaration
  name: (method_index_expression
    table: (_) @parent
    method: (identifier) @name)) @method

(function_declaration
  name: (identifier) @name) @function
