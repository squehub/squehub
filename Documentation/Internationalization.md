# Internationalization

SqueHub's translation service is owned by each Application. A single-language application can leave the shipped `Config/Translation.php` unchanged. Applications that serve more than one locale declare their supported locales and add JSON catalogs under `Project/Translations`.

Internationalization passed Windows and user-run native Linux qualification with ICU and PHP `intl` enabled. The focused Linux run passed 12 tests and 105 assertions; the broad regression passed 1,210 tests and 8,581 assertions with 13 skips. Basic translation does not require PHP `intl`; ICU pluralization and locale-aware number, currency, and date/time formatting do. Studio reports whether `intl` is loaded without reading catalog values.

## Configure locales

`Config/Translation.php` contains:

```php
return [
    'default' => 'en',
    'fallback' => 'en',
    'supported' => ['en', 'fr', 'pt', 'pt-BR'],
];
```

The default and fallback must appear in `supported`. Locale identifiers use canonical language/region form such as `en`, `fr-FR`, or `pt-BR`; invalid or unsupported identifiers fail clearly. SqueHub does not derive its locale from `Accept-Language`, a network address, or a process-global PHP locale. Select a locale explicitly in application code or middleware after applying your own user preference policy.

```php
use App\Plugins\Translation;

Translation::setLocale('fr');
echo Translation::locale(); // fr
```

Outside a request or Queue job, `setLocale()` changes the selected default for that Application's future scopes. Inside a request or job, it affects only that scope. The framework restores the previous scope on completion, including an error path. Each Application has an independent manager and catalog root. Queue dispatch does not automatically capture a request locale; a job that needs a specific locale should select it explicitly from its validated, JSON-safe payload or recipient preference. Worker scopes return to the Application selection after each attempt.

## Create catalogs

One JSON file represents a group. For example, create `Project/Translations/en/messages.json`:

```json
{
  "welcome": "Hello, :name",
  "orders": {
    "ready": "Your order is ready"
  },
  "items": "{count, plural, one {# item} other {# items}}"
}
```

Add `Project/Translations/fr/messages.json` with the same key structure. Look up `messages.welcome`, `messages.orders.ready`, or `messages.items`. Catalogs are bounded JSON objects containing nested objects and plain strings; PHP code and templates in catalog values are never executed. Keys are validated and file reads reject linked or outside-root paths. Catalogs are read on demand; there is no translation cache to warm or invalidate in the current implementation.

```php
use App\Plugins\Translation;

$message = Translation::get('messages.welcome', ['name' => 'Ada']);
$french = Translation::get('messages.welcome', ['name' => 'Ada'], locale: 'fr');
```

`:name` placeholders accept scalar parameters. Missing parameters remain visible as placeholders; extra parameters are ignored. A missing translation returns its key. No parameter or translated string becomes trusted HTML automatically. Escape it when placing it in HTML.

For a requested locale, SqueHub checks that locale, a supported language parent if applicable, the configured fallback, then that fallback's supported parent. For `pt-BR` with `pt` and `en` supported, this gives `pt-BR → pt → en`. Duplicate candidates are checked once. The locale that supplies a plural message is the locale used for its ICU plural rule.

## Plurals and formatting

Enable PHP `intl` to use ICU plural patterns and formatters. Without it, ordinary `get()` lookups still work; `plural()`, `number()`, `currency()`, and `dateTime()` raise a focused capability exception.

```php
use App\Plugins\Translation;

echo Translation::plural('messages.items', 2);
echo Translation::number(1234567.5, locale: 'fr');
echo Translation::currency(2500, 'NGN', locale: 'en');
echo Translation::dateTime($createdAt, locale: 'fr', timezone: new DateTimeZone('Africa/Lagos'));
```

`plural()` takes a finite count and formats an ICU message; it does not use an English-only singular rule. `number()` accepts an optional maximum fraction-digit count. `currency()` requires an explicit three-letter currency code: locale controls presentation, not which currency the amount represents. `dateTime()` accepts a `DateTimeInterface`, optional locale and timezone, and date/time style names (`none`, `short`, `medium`, `long`, `full`). Locale never silently chooses a timezone; omitted timezone uses the value's timezone.

## Views

The View compiler provides one escaped directive:

```php
<h1>@translate('messages.welcome', ['name' => $name])</h1>
```

It uses the same Application translation manager as controllers and Mail/Notification code that calls the gateway. The legacy Mail template renderer is separate from the general View engine. Directive output is HTML-escaped like `{{ ... }}`. If an application deliberately renders trusted HTML, use the View engine's explicit raw-output policy and validate the source. `@translate` accepts a key, optional parameters, and optional locale. There is no `__()`, `trans()`, `lang()`, or `t()` alias.

## Package catalogs and overrides

An enabled Package `Commerce` may include `Project/Packages/Commerce/Translations/fr/orders.json` and use the key `Commerce::orders.paid`. Package names are explicit and must match the active Package identity. Disabled Packages cannot supply translations.

An application can override that Package's exact-locale catalog at `Project/Translations/Packages/Commerce/fr/orders.json`. For each locale candidate, the explicit Project override is checked before the enabled Package file. The requested locale outranks any fallback locale: an available Package `fr` value is selected before a Project `en` override. Ordinary application keys use `Project/Translations/{locale}/{group}.json`; they never collide with namespaced Package keys.

## Diagnostics and limits

Studio's Infrastructure inspection shows configured default, fallback, supported locale labels, and PHP `intl` availability. It does not display translated content, parameters, or catalog paths. Ordinary missing keys return their names; there is no strict missing-key mode, automatic locale negotiation, remote translation provider, or catalog cache in the current implementation.

Queue locale propagation is intentionally explicit. Queue jobs may execute after the original request ends or in another process, so a user-facing job should carry only a deliberate locale preference in its own safe payload and select that locale during handling. The worker clears the selection before its next job. Queued Mail still follows the existing Queue retry and at-least-once delivery rules.

For direct queued Mail, build translated subject and body text before `Mail::queue()`: the queued message keeps that already rendered text. A queued Notification builds its Mail message in the worker, so its queue-safe notification payload must include an explicit locale when delivery should use a recipient-specific language. Translation does not infer a language from the Queue envelope or a recipient's address. Integration tests cover synchronous Mail/Notifications and both queued paths with worker locale reset.
