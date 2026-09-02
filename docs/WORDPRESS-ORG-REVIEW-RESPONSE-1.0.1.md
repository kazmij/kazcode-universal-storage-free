Hello,

Thank you for the review. I have prepared an updated 1.0.1 package.

1. The unnecessary `vendor/aws/aws-crt-php/format-check.py` development helper has been removed from the distributed ZIP, and the package audit now fails if Python files are included in the release ZIP again.
2. `readme.txt` now includes a clearer `External Services` section describing the object-storage providers and storage operations used by the plugin.
3. The `s3.amazonaws.com` URLs are generated Media Library object URLs for administrator-configured storage, not external assets required by KAZCODE itself.
4. The updated ZIP was tested on a clean WordPress installation with `WP_DEBUG=true`.

Best regards,
KAZCODE
