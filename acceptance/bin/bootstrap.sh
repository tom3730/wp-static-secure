#!/bin/sh
set -eu

wp core is-installed >/dev/null 2>&1 || wp core install --url="$WP_SITE_URL" --title="WP Static Secure Acceptance" --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" --admin_email="$WP_ADMIN_EMAIL" --skip-email

php /acceptance/bin/release-download.php
wp plugin install /tmp/wp-static-secure.zip --force --activate
version="$(wp plugin get wp-static-secure --field=version)"
[ "$version" = "${WPS_RELEASE_TAG#v}" ] || { echo "Release plugin version mismatch: $version" >&2; exit 20; }

wp theme install "$WPS_THEME" --version="$WPS_THEME_VERSION" --force --activate
wp plugin install contact-form-7 --version="$WPS_CF7_VERSION" --force --activate

wp post delete $(wp post list --post_type=post,page --format=ids) --force >/dev/null 2>&1 || true

printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' | base64 -d > /tmp/acceptance.png
cat > /tmp/acceptance.pdf <<'EOF'
%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj
trailer<</Root 1 0 R>>
%%EOF
EOF
image_id="$(wp media import /tmp/acceptance.png --title='Acceptance Image' --porcelain)"
pdf_id="$(wp media import /tmp/acceptance.pdf --title='Acceptance PDF' --porcelain)"
image_url="$(wp post get "$image_id" --field=guid)"
pdf_url="$(wp post get "$pdf_id" --field=guid)"

about_id="$(wp post create --post_type=page --post_status=publish --post_title='Acceptance About' --post_name='acceptance-about' --post_content='<p>Acceptance fixture page.</p>' --porcelain)"
wp post create --post_type=post --post_status=publish --post_title='Acceptance Post' --post_name='acceptance-post' --post_content="<p>Fixture post with an <a href='${WP_SITE_URL}/acceptance-about/'>internal link</a>.</p><img src='${image_url}' alt='Acceptance Image'><p><a href='${pdf_url}'>Acceptance PDF</a></p>" >/dev/null
wp post create --post_type=page --post_status=publish --post_title='Generic Form' --post_name='generic-form' --post_content='<form data-wpss-form="acceptance-contact"><label>Email <input type="email" name="email" required></label><label>Message <textarea name="message" required></textarea></label><button type="submit">Send</button></form>' >/dev/null

cf7_id="$(wp post create --post_type=wpcf7_contact_form --post_status=publish --post_title='Acceptance CF7' --post_content='<label>Email [email* your-email]</label><label>Message [textarea* your-message]</label>[submit "Send"]' --porcelain)"
wp post create --post_type=page --post_status=publish --post_title='CF7 Form' --post_name='cf7-form' --post_content="[contact-form-7 id=\"${cf7_id}\" title=\"Acceptance CF7\"]" >/dev/null

wp eval 'global $wpdb; $store = new WPStaticSecure\\Forms\\WordPressSubmissionStore($wpdb); if (count($store->list(null, 500)) === 0) { $store->save(new WPStaticSecure\\Forms\\Submission("acceptance-contact", ["email" => "acceptance@example.invalid", "message" => "Acceptance fixture"])); }'

wp rewrite structure '/%postname%/' --hard >/dev/null
wp rewrite flush --hard >/dev/null
printf 'Acceptance bootstrap complete. about_id=%s image_id=%s pdf_id=%s cf7_id=%s\n' "$about_id" "$image_id" "$pdf_id" "$cf7_id"
