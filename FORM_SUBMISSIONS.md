# Form submission architecture and security

WP Static Secure treats forms as an explicit dynamic capability. A form submission is application data; email, chat, or other notification delivery is not part of submission correctness.

## Initial supported form

The first adapter is deliberately opt-in. A generic HTML form is supported only when it has a `data-wpss-form` identifier:

```html
<form data-wpss-form="contact">
  <input type="email" name="email">
  <textarea name="message"></textarea>
</form>
```

During static export the form processor rewrites a supported form to an explicitly configured absolute HTTP(S) submission endpoint, forces `method="post"`, and adds a hidden `_wpss_form_id` value. Unmarked forms are left unchanged and are not claimed as supported.

Generated forms contain no private credentials, API keys, WordPress nonces, or privileged endpoint addresses.

## Adapter boundary

Form compatibility lives behind `FormAdapter`:

- `supports()` decides whether an adapter explicitly recognizes a form;
- `extractSchema()` derives the form identifier and allowed field names;
- `rewrite()` changes the generated form to the configured submission endpoint;
- `validateSubmission()` converts untrusted request data into a normalized `Submission`.

The generic adapter accepts form identifiers matching `^[a-z][a-z0-9_-]{0,63}$`. Field names are restricted to letters, digits, dot, underscore, and hyphen. File inputs are not supported by the initial adapter. Arbitrary nested values are rejected.

The normalized stored schema is:

```json
{
  "form_id": "contact",
  "fields": {
    "email": "user@example.com",
    "message": "Hello"
  }
}
```

Only fields present in the extracted allowlist may be stored.

## Submission endpoint boundary

`SubmissionEndpoint` is a narrow submission service. It has no WordPress URL, HTTP client, RPC forwarding, or generic proxy capability. It routes only known form identifiers to a registered adapter/schema pair and persists only the normalized submission returned by that adapter.

The included `JsonlSubmissionStore` is a minimal local/test storage implementation. It appends normalized submissions with an exclusive file lock. It is not presented as a production multi-node inbox or database.

Notifications are intentionally absent from the core endpoint. A submission is considered accepted only after the store succeeds. Future notification integrations must consume already-persisted submissions so notification failure cannot erase or invalidate accepted submission data.

## Public HTTP transport

`SubmissionHttpTransport` is the concrete MVP boundary between an Internet-facing HTTP runtime and `SubmissionEndpoint`. It deliberately accepts only:

- the `POST` method;
- `application/x-www-form-urlencoded` request bodies;
- request bodies at or below an explicitly configured byte limit (64 KiB by default);
- a request `Origin` that passes the endpoint's exact HTTP(S) origin allowlist;
- one unambiguous scalar value per submitted field.

The transport decodes form bodies without PHP's `parse_str()` key normalization so field names such as `user.email` remain exact. Invalid percent encoding, invalid UTF-8, and duplicate field names are rejected before schema validation. Unknown form identifiers and unknown fields continue to be rejected by the existing endpoint/adapter boundary.

The transport returns JSON responses: `201` for an accepted and persisted submission, `405` for unsupported methods, `413` for oversized requests, `415` for unsupported media types, `400` for malformed form encoding, and `422` for submissions rejected by origin/form/schema validation. These response classes are intentionally coarse so the public endpoint does not expose detailed validation internals.

`SubmissionHttpTransport` does not itself open a socket, register a WordPress route, or choose a hosting provider. A deployment adapter may map its request inputs and response object to PHP-FPM, a serverless runtime, or another narrowly scoped HTTP service. That adapter must not add a generic WordPress proxy path.

## WordPress Submission Inbox

For the MVP, `WordPressSubmissionStore` is the WordPress-managed system of record. Plugin activation creates a dedicated `{prefix}wpss_submissions` table containing only:

- an internal numeric submission id;
- form identifier;
- normalized submitted fields as JSON;
- status (`new`, `in_progress`, `done`, or `spam`);
- UTC submission time.

IP address, User-Agent, referrer, cookies, and other request metadata are not stored by default.

The WordPress admin Inbox is available under **Tools → Submission Inbox**. Viewing and status changes require the `manage_options` capability. Status changes use WordPress nonces in addition to the capability check. Submission values are escaped before rendering in the admin UI.

No anonymous WordPress REST route, AJAX action, or other public WordPress endpoint is created for the Inbox. The public HTTP transport may persist through `WordPressSubmissionStore` only when it runs in an explicitly designed trusted runtime that can reach the storage boundary without exposing WordPress itself. The Inbox must not be used as a shortcut that proxies anonymous public traffic into WordPress.

## Retention and export

Submission retention is a deployment policy because form contents may contain personal or confidential data. The MVP does not silently delete submissions and does not impose a universal retention period. Operators should define a retention period appropriate to the form purpose, legal obligations, and backup policy. Future automated retention should be explicit, configurable, and auditable.

The MVP also does not provide a bulk export endpoint. A future export feature should require the same or stronger capability than Inbox access, escape spreadsheet-dangerous values where CSV is used, avoid anonymous download URLs, and document whether deleted submissions remain in backups. Direct database export remains an operator responsibility until that feature exists.

## Origin, CORS, and CSRF decision

The initial endpoint requires an exact HTTP(S) `Origin` match against a configured allowlist. Missing, malformed, or unapproved origins are rejected. This is the initial browser CSRF boundary for cross-site form posts.

CORS is not made permissive by this core code. The MVP HTTP transport does not emit wildcard CORS headers. A deployment that adds browser JavaScript access may emit CORS headers only for configured allowed origins; plain HTML form submission does not require wildcard CORS.

Origin validation is one layer, not a universal CSRF solution. Deployments that intentionally accept clients that do not send an `Origin` header require a separate explicitly designed authentication/CSRF model rather than weakening the default behavior.

## Rate limiting and spam decision

Rate limiting and spam classification are required production concerns but are not implemented as hidden behavior in the generic adapter. The public HTTP deployment must apply rate limiting before calling `SubmissionHttpTransport`; the transport's byte limit is not a substitute for request-rate controls. The rate limiter should be provider/runtime specific and independently testable rather than coupled into form schema validation.

Spam controls should classify or quarantine persisted submissions without turning the endpoint into a WordPress proxy.

The current boundary therefore provides form rewriting, bounded HTTP request handling, exact origin enforcement, schema validation, normalized durable storage, an authenticated WordPress Inbox, and separation from notification delivery. Distributed rate limiting, spam scoring automation, bulk export, retention automation, and third-party form-plugin adapters remain follow-up work.
