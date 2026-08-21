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

## Origin, CORS, and CSRF decision

The initial endpoint requires an exact HTTP(S) `Origin` match against a configured allowlist. Missing, malformed, or unapproved origins are rejected. This is the initial browser CSRF boundary for cross-site form posts.

CORS is not made permissive by this core code. An HTTP transport that exposes `SubmissionEndpoint` must emit CORS headers only for configured allowed origins when browser JavaScript access is required. Plain HTML form submission does not require wildcard CORS.

Origin validation is one layer, not a universal CSRF solution. Deployments that intentionally accept clients that do not send an `Origin` header require a separate explicitly designed authentication/CSRF model rather than weakening the default behavior.

## Rate limiting and spam decision

Rate limiting and spam classification are required production concerns but are not implemented as hidden behavior in the generic adapter. A public HTTP transport must apply bounded request-size limits and rate limiting before calling `SubmissionEndpoint`. Spam controls should classify or quarantine persisted submissions without turning the endpoint into a WordPress proxy.

The current local/test boundary therefore provides schema validation, origin enforcement, normalized storage, and separation from notification delivery. Production HTTP transport, distributed rate limiting, spam scoring, inbox workflow, and third-party form-plugin adapters remain follow-up work.
