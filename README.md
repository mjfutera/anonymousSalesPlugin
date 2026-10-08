# Anonymous Sales

WordPress plugin: sell digital files for cryptocurrency without collecting any customer data. Orders are anonymous (order number plus a secret receipt token), payments go through [NOWPayments](https://nowpayments.io).

The directory description, installation steps, FAQ, external services and privacy notes are in [`readme.txt`](readme.txt), which is the file WordPress.org reads.

## Build the release package

```
node bin/build.mjs
```

Creates `dist/anonymous-sales/` and `dist/anonymous-sales.zip` without git files, `bin/`, `dist/` and `.wordpress-org/` (see `.distignore`).

## License

GPL v2 or later. The bundled FPDF 1.86 (Olivier Plathey) is released under a permissive license.
