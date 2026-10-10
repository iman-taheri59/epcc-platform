# Optional local font drop-in

The image installs `fonts-vazirmatn` from the Debian archive, and the build
**fails** if fontconfig cannot then see a Vazirmatn family (see the verification
step at the bottom of `../Dockerfile`). That is deliberate:

* `PdfExportTest::test_the_persian_font_is_embedded_rather_than_substituted`
  asserts `pdffonts` reports `Vazirmatn`. A substituted font renders Persian as
  boxes or disconnected letters and still produces a valid PDF - the exact
  silent failure mode risk IR-R2 is about.
* The Filament panel uses `LocalFontProvider` for the same family, so a missing
  font degrades every admin screen too.

For an **air-gapped build host** with no route to the Debian archive, drop the
TTFs here and they are installed into
`/usr/share/fonts/truetype/epcc/` during the build:

```bash
# On a connected machine
npm pack vazirmatn@33.0.3 && tar xzf vazirmatn-33.0.3.tgz
cp package/fonts/ttf/Vazirmatn-*.ttf docker/php/fonts/
```

Only the main family belongs here. The `misc/` variants in that package
(Farsi-Digits, Non-Latin, Round-Dots, UI) are different families and would
compete with the one the views ask for by name.

Nothing in this directory is required for a normal build; it exists so an
operator can supply the font without editing the Dockerfile.
