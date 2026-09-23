# App Store release

The App Store reads metadata from `appinfo/info.xml` inside a signed release archive. The screenshots in that file are served from this repository and require the repository to be public.

1. Review the source and Git history before changing repository visibility. Confirm that the three image URLs in `appinfo/info.xml` return images without authentication.
2. Run `npm ci && npm run build`, validate `appinfo/info.xml` against the [App Store schema](https://apps.nextcloud.com/schema/apps/info.xsd), and run the PHP checks in a Nextcloud server checkout. Check that the archive contains `css/`, `js/`, `LICENSE`, and no `node_modules/` or `.git/`.
3. Obtain an App Store certificate for the app ID `deckgithubsync` and register the app ID, following the [official App Developer Guide](https://nextcloudappstore.readthedocs.io/en/latest/developer.html). Keep the private key outside the repository.
4. Tag the tested commit and build the archive from it:

   ```bash
   mkdir -p dist
   git archive --format=tar --prefix=deckgithubsync/ HEAD | gzip -9 > dist/deckgithubsync-0.5.0.tar.gz
   tar -tzf dist/deckgithubsync-0.5.0.tar.gz | head
   ```

   Its only top-level directory must be `deckgithubsync/`. A GitHub-generated source archive has a different top-level name and is unsuitable.
5. Sign the *exact* archive bytes with `openssl dgst -sha512 -sign ~/.nextcloud/certificates/deckgithubsync.key dist/deckgithubsync-0.5.0.tar.gz | openssl base64 -A`.
6. Publish the archive at a public HTTPS URL, then submit that URL and signature through the [App Store release form](https://apps.nextcloud.com/) or API. Verify the listing, screenshots, and installation from the store.

Do not claim a GitHub Projects board screenshot until an actual Project is connected and captured. The current gallery shows a local Deck demonstration and an unconnected setup screen.
