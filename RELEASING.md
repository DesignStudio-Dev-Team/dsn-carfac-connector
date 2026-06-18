# Releasing DSN Carfac

When a release is requested:

1. Update both version declarations in `dsn-carfac.php`.
2. Run PHP syntax checks across the plugin.
3. Commit the release changes and push `main` to GitHub.
4. Confirm the version tag does not already exist on the Carfac remote. Never
   replace a tag that has already been published. This clone may contain stale
   local Powerall tags; if the desired tag exists only locally, delete that
   local tag before continuing.
5. Create an annotated tag matching the plugin version, such as `v1.1.1`.
6. Push the tag to GitHub.
7. Confirm the `Create DSN Carfac Release` workflow completed and attached
   `dsn-woo-carfac-connector-vX.Y.Z.zip` to the GitHub Release.

The release ZIP must contain one root directory named
`dsn-woo-carfac-connector` with `dsn-carfac.php` inside it. The WordPress
updater intentionally ignores releases that do not include this ZIP asset.
