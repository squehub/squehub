# Application Kits

Each Kit definition lives in `Project/Kits/<KitName>/` with a `kit.json` manifest and `<KitName>.php` lifecycle class. Kit discovery reads the manifest without executing the class. Normal application requests run published Project code and enabled Packages; they do not boot Kit classes.

Use `php squehub kit:list` to inspect installed Kits. A local `kit:install <source>` copies a definition but leaves it disabled. Review `kit:enable <name> --preview` before applying its Package requirements and application files. See the [official Kits guide](https://www.squehub.com/docs/v2.x/kits) for the manifest and lifecycle contracts.
