# PHP compatibility

KAZCODE Universal Storage Free and Pro support PHP **8.1, 8.2, 8.3, 8.4 and
8.5**. Both distributables use the same minimum: PHP 8.1.

> PHP 8.1 reached end of life on 31 December 2025. It remains supported by the
> plugin for compatible-hosting reach, but it no longer receives PHP security
> fixes. Use PHP 8.2 or newer whenever the hosting provider allows it.

## Release qualification

Every supported release must provide evidence for both Free and Pro on every
listed PHP version:

| PHP | Free | Pro | Required evidence |
| --- | --- | --- | --- |
| 8.1 | required | required | Composer platform check, syntax lint, PHPUnit, final ZIP activation smoke |
| 8.2 | required | required | Composer platform check, PHPUnit, final ZIP activation smoke |
| 8.3 | required | required | Composer platform check, PHPUnit, final ZIP activation smoke |
| 8.4 | required | required | Composer platform check, PHPUnit, final ZIP activation smoke |
| 8.5 | required | required | Composer platform check, PHPUnit, final ZIP activation smoke and deprecation review |

CI runs the Free/Pro PHPUnit matrix on all five interpreters. The
`PHP 8.1 release ZIP activation (Free + Pro)` gate builds the scoped packages
and installs and activates their final ZIPs on clean WordPress 6.7/PHP 8.1
before a public compatibility claim is made. Browser/storage E2E is run on the
qualified WordPress/PHP intersection; it does not replace the matrix.

PHP 8.6 is not advertised until it is stable and separately qualified.
