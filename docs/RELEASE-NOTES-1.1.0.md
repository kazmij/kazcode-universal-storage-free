# KAZCODE Universal Storage 1.1.0

## Safe migration and resilient large transfers

KAZCODE Universal Storage 1.1.0 adds two Free import paths for moving existing media
inventory safely from **WP Offload Media** and **Advanced Media Offloader**. Detection and
scanning are read-only. Before importing, administrators must run a dry-run against a
selected KAZCODE storage profile; the import verifies each exact remote object key and
adopts verified inventory without re-uploading it.

Credentials are never copied from another plugin. Imports do not alter competitor options,
metadata, database rows, or remote objects. A transient, authorization, or network HEAD
failure is reported as an error and is never treated as a missing object.

Large local uploads now use durable, crash-resumable multipart transfer sessions. On
resume, KAZCODE reconciles the provider's uploaded parts, continues only with the same
source, and verifies the completed destination before local cleanup is allowed.

## Free and Pro boundary

The multipart engine is shared Free infrastructure. The Free plugin includes the two
supported competitor importers and local-upload recovery, but it does not gain an ungated
provider-to-provider migration workflow.

Cross-provider and cross-bucket migration remains a Pro capability. Pro uses the shared
resumable transfer engine for large transfers and keeps the source delivery URL active
until the destination has been verified and committed.

## Upgrade and recovery

The schema adds durable import-run and transfer-session state. Existing storage profiles,
object inventory, and media behavior are retained. Default uninstall preserves recovery
state; the existing opt-in purge setting removes it. Neither uninstall mode deletes remote
objects or WordPress media.

Not included in 1.1.0: Media Cloud, WP-Stateless, or Next3 importers.
