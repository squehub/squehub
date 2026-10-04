# SqueHub v2 validation foundation

Request rules remain the runtime input check. The [Application Contract](ApplicationContract.md) declares documented request schemas explicitly; The contract system does not translate arbitrary validation rules into JSON Schema or prove that a declaration matches runtime behavior. Keep the two definitions aligned in application code and tests. A declared 422 response uses the existing API error envelope rather than a second validation-error format.

Validation is available in the core repository. It checks input without trimming, casting, or rewriting it. The separate public v1.x documentation is unchanged.

## Request validation

```php
use App\Plugins\Request;

public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string|max:100',
        'email' => 'required|email|unique:users,email',
    ]);

    // $data contains only fields named by passing rules.
}
```

`Request::validate($rules, $messages = [])` returns validated data or throws `App\Validation\ValidationException`. The Request validates its captured form or JSON body plus uploaded files. Query parameters, route parameters, headers, cookies, and server values stay separate. The Request's `all()` and `file()` contracts are unchanged.

Filtering to named fields reduces accidental extra input, but validation does not replace a Model's fillable/guarded policy or authorization checks.

Typed application data is optional. `Request::validatedAs(ClassName::class, ?array $rules = null, array $messages = [])` runs this same `validate()` path first, then constructs a plain PHP value from declared constructor fields. Explicit rules work with any suitable class; omitted rules require `App\Plugins\ValidatedData::rules()`:

```php
use App\Plugins\{Request, ValidatedData};

final readonly class CreateUserData implements ValidatedData
{
    public function __construct(public string $name, public string $email) {}

    public static function rules(): array
    {
        return ['name' => 'required|string', 'email' => 'required|email'];
    }
}

$data = $request->validatedAs(CreateUserData::class);
// The existing array API remains equally valid:
$values = $request->validate(CreateUserData::rules());
```

Validation and the typed constructor are separate checks: the Validator retains its existing no-cast output, then a strict mapper converts only declared fields (for example a validated canonical decimal string into `int`). Unknown input is not passed to the constructor. Missing/invalid values become the same safe field validation flow; invalid class definitions and constructor failures remain application errors. Nested typed parameters require `#[App\Plugins\NestedData]` and explicit nested validation rules. `DataMapper::map()` is available for trusted values outside Request validation but never validates raw input by itself. See [Typed application data](ApplicationData.md) for the exact conversion policy, browser/API examples, and serialization boundary.

Typed validation is implemented and covered by Windows and native Linux qualification. See [current status](Status.md) for verification limits.

The Application registers `ValidatorFactory` for dependency injection. Standalone code can use one nonthrowing entry point:

```php
$result = validator($data)->check([
    'age' => 'nullable|integer|min:18',
]);

if ($result->fails()) {
    $errors = $result->errors();
}
$passedFields = $result->validated();
```

`ValidationResult` exposes `passes()`, `fails()`, `errors()`, `first($field)`, and `validated()`. On failure, `validated()` contains only fields that passed all of their rules; it is not a complete validated request. `Request::validate()` throws on any error. The exception contains safe field errors and a generic message, not the submitted data.

## Rules and errors

String rules are separated with `|`; array form accepts rule strings and objects. Supported rules are `required`, `present`, `nullable`, `string`, `integer`, `numeric`, `boolean`, `array`, `email`, `url`, `min`, `max`, `between`, `size`, `in`, `not_in`, `same`, `different`, `confirmed`, `date`, `before`, `after`, `regex`, `unique`, `exists`, `file`, `image`, and `mimes`. Invalid definitions fail during setup. Regex patterns containing `|` should use array form, for example `['string', 'regex:/^(A|B)$/']`.

`required` rejects a missing key, null, empty string, empty array, or an upload marked `UPLOAD_ERR_NO_FILE`. Zero, `'0'`, and false are present. `present` checks key existence even when the value is null or empty. `nullable` skips non-presence rules for null and no-file uploads; it does not cancel `required` or `present`. Missing optional fields are omitted from validated output. Other invalid upload errors fail file rules.

`string` accepts only strings. `integer` accepts PHP integers and valid base-10 integer strings within PHP's integer range. `numeric` accepts finite PHP numbers and numeric strings. `boolean` accepts true, false, 1, 0, `'1'`, `'0'`, `'true'`, and `'false'`; it does not cast the result. `array` requires a PHP array. `email` uses PHP's email syntax validator. `url` accepts syntactically valid HTTP or HTTPS URLs. `date` uses strict `Y-m-d` calendar dates; `before` and `after` compare another input date field or a literal `Y-m-d` date. `confirmed` compares with `<field>_confirmation`. `same` and `different` compare against original input with strict equality. `in` and `not_in` compare scalar string representations without loose PHP equality.

`min`, `max`, `between`, and `size` operate on numeric value for numbers and fields with an `integer` or `numeric` rule, UTF-8 character count for strings, element count for arrays, and kilobytes (bytes / 1024) for valid uploaded files. With no `mbstring` extension, string length falls back to bytes. A failed type rule suppresses dependent size messages. No rule sanitizes or converts the original value.

Errors are grouped by concrete field path and keep field and rule order. The validator can collect multiple messages for one field. Custom messages use `field.rule`, then a wildcard pattern such as `items.*.quantity.min`, then a rule-level key such as `min`, then the built-in message. Use `$validator->labels(['first_name' => 'given name'])` for human-readable message labels; error keys remain technical field paths. Default messages never include submitted values.

## Nested fields and arrays

```php
$data = $request->validate([
    'profile.name' => 'required|string',
    'items' => 'required|array|min:1',
    'items.*.product_id' => 'required|integer|exists:products,id',
]);
```

Errors use actual paths such as `items.2.product_id`. Passing output preserves nesting and array indexes, and does not copy unvalidated sibling keys. A missing parent can produce a required error for a named child. A wildcard over an empty or missing parent has no child instances; put `required` or `min:1` on the parent when at least one item is needed. Parent array rules and child rules can be combined without admitting unvalidated child fields.

## Custom and database rules

Implement `App\Plugins\ValidationRule::validate(string $field, mixed $value, array $data): ?string`. Return null on success or a safe user-facing message on failure. Pass a rule object or a class string in an array of rules. Class strings resolve through the Application container during a Kernel request or an injected `ValidatorFactory`, so constructor dependencies can be bound normally. Unexpected exceptions from custom rules propagate; they do not become ordinary field errors.

```php
use App\Plugins\Rule;

$rules = [
    'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
    'role_id' => 'required|integer|exists:roles,id',
];
```

`unique:table,column` and `exists:table,column` use the configured SqueHub database manager, validated SQL identifiers, and bound values. `Rule::unique(...)->ignore($id, $keyColumn = 'id')` is the structured update form. These are raw table rules: they include soft-deleted rows and do not consult Model scopes. Wildcard database rules, including the structured unique rule, collect candidate values and query in batches of at most 500 distinct values; nonwildcard checks use one existence query per evaluated rule. A failed database connection or query remains an infrastructure error. A passing `unique` check does not guarantee a later insert against a concurrent writer; retain database unique constraints.

## Files

`Request::validate()` converts captured PHP upload entries to `UploadedFile` values for validation. An ordinary body string containing a local path is not a file. `file` requires a successful upload entry with a readable temporary file. A manually constructed `UploadedFile` is not independent proof that PHP received the file over HTTP; normal web requests obtain file entries from PHP's `$_FILES`. `UPLOAD_ERR_NO_FILE` is absent for `required` and skippable with `nullable`; other upload errors are invalid. `mimes` uses PHP `fileinfo` to inspect content, not the client filename, for `pdf`, `docx`, `jpg`, `jpeg`, `png`, `gif`, `webp`, and `txt`. A DOCX is accepted only when `fileinfo` identifies its specific MIME type; generic ZIP detection is not treated as proof of DOCX. `image` checks actual GIF, JPEG, PNG, or WebP image structure with `getimagesize`. These are format checks, not full content sanitization. `min` and `max` use kilobytes. Validation neither moves nor stores uploaded files.

## HTTP behavior and existing notifications

The Kernel renders `ValidationException` through its existing ExceptionHandler. Within an enabled [API response scope](ApiResponses.md), it returns HTTP 422 with `error.code: validation_failed`, the public message `The submitted data is invalid.`, existing field messages in `error.details`, and a server-generated `request_id`. Validation runs once; API failures do not redirect or flash input/errors. Messages pass the API renderer's bounded safety checks and redaction; custom validation messages must still be suitable for public output.

Outside API scope, JSON requests receive HTTP 422 with `{"message":"Validation failed.","errors":{"email":["..."]}}`. An unsafe HTML form request with a safe previous internal destination receives HTTP 303 and flashes field errors and filtered old input for the next request. This also applies when a POST browser form uses `_method` to reach a PUT, PATCH, or DELETE route; the redirect excludes `_method` and CSRF form metadata from old input. Without a safe destination, or for GET validation, HTML receives a simple escaped HTTP 422 page. These responses omit input values, traces, and database diagnostics. See [Forms and Validation UX](Forms.md) for `@error`, ErrorBag summaries, checked/selected state, redirect safety, and template usage.

The existing `App\Core\Notification` and `App\Components\Notification` classes retain their historical storage keys and share the Application-owned [session](Sessions.md) flash lifecycle. Validation errors use the separate `_validation_errors` key. The normal web Kernel checks [CSRF](Csrf.md) before controllers run. A failed CSRF check returns 403 without calling validation or saving submitted input. The configured CSRF field need not appear in validation rules.

## Application-facing Plugins import

Application code may import `App\Plugins\Validator`, `App\Plugins\Rule`, `App\Plugins\ValidationResult`, `App\Plugins\ErrorBag`, `App\Plugins\ValidatedData`, `App\Plugins\NestedData`, `App\Plugins\DataMapper`, and `App\Plugins\DataMappingException`. These entries delegate to the current subsystems; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
