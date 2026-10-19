# Primiko - Quote Requests by Trade

Quote request forms made for each trade: the right questions in the right order, with photos and the site address.

A trade template is a JSON file in [`templates/`](templates/): steps, fields, display conditions, help texts and the messages sent to the visitor. The format is described in [`docs/modele-metier.md`](docs/modele-metier.md). Nothing in the code depends on a given trade.

## Layout

- `primiko.php`: plugin header, constants, autoloader.
- `includes/`: one class per file (`Primiko\Post_Types` is `class-post-types.php`).
  - `Templates` reads and checks the templates, `Conditions` evaluates `show_if` on the server.
  - `Customizations` keeps what the business changed on its form (hidden steps, questions and answers, renamed texts, messages), as differences with the template.
  - `Renderer` builds the form markup, shared by `Block` and `Shortcode`.
  - `Rest` exposes `GET primiko/v1/token` and `POST primiko/v1/submit`, handled by `Submission`, with `Token` for the signed single use token.
  - `Private_Uploads` keeps the photos out of the media library, `Mailer` sends the emails, `Entries` turns answers into labelled text.
  - `Form_Settings` and `Request_View` are the admin boxes of a form and of a request, `Request_List` the columns, views and bulk actions of the list.
  - `Settings` holds the retention period, `Retention` deletes older requests every night, `Privacy` plugs requests into the personal data export and erase tools.
- `assets/form.js` and `assets/form.css`: visitor form, plain JavaScript, no build.
- `src/block/`: the block editor script, `src/admin/`: the questions editor of the form screen. Both built with `@wordpress/scripts` into `build/` (`webpack.config.js` adds the admin entry to the default build).
- `includes/template-strings.php`: generated table of the template texts and their translations, read when a template is translated.

## WP-CLI

```
wp primiko list [--status=<new|done>] [--since=<YYYY-MM-DD>] [--format=<table|csv|json|count>]
wp primiko export [--status=<new|done>] [--since=<YYYY-MM-DD>]   # JSON, every answer
wp primiko delete <id>... [--yes]                                # with the photos
wp primiko purge [--dry-run]                                     # retention period, now
wp primiko templates [--format=<table|csv|json>]
```

## Development

```
npm install
npm run build              # block
npm run i18n:templates     # after changing a template
composer install && composer lint
```

Translations: the sources are in English. Only `languages/primiko.pot` ships in the package, the French `.po` and `.mo` files are kept for local work.

## Release

Tag `X.Y.Z` after updating the version in `primiko.php` (header and constant) and the stable tag in `readme.txt`. The deploy workflow builds the block and pushes to the WordPress.org SVN repository.
