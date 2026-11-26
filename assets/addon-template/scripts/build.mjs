// Build the release ZIP: version check → stage an allowlist → zip nested under the slug folder.
// Usage: node scripts/build.mjs   (needs `npm i -D archiver`)
import { createWriteStream, existsSync, mkdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import archiver from "archiver";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const slug = "acme-store-addon";
const allowlist = [`${slug}.php`, "src", "assets", "languages", "readme.txt"];

const main = readFileSync(join(root, `${slug}.php`), "utf8");
const header = main.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1];
const constant = main.match(/_VERSION',\s*'([^']+)'/)?.[1];
const pkg = JSON.parse(readFileSync(join(root, "package.json"), "utf8")).version;

if (!header || header !== constant || header !== pkg) {
  console.error(`Version mismatch: header ${header}, constant ${constant}, package.json ${pkg}`);
  process.exit(1);
}

const changelog = existsSync(join(root, "CHANGELOG.md")) ? readFileSync(join(root, "CHANGELOG.md"), "utf8") : "";
if (!changelog.includes(`## ${header}`) && !changelog.includes(`## [${header}]`)) {
  console.warn(`CHANGELOG.md has no section for ${header}`);
}

mkdirSync(join(root, "release"), { recursive: true });
const target = join(root, "release", `${slug}-${header}.zip`);
const output = createWriteStream(target);
const zip = archiver("zip", { zlib: { level: 9 } });

zip.pipe(output);
for (const entry of allowlist) {
  const path = join(root, entry);
  if (!existsSync(path)) continue;
  // Archive names always use "/" — a ZIP with "\" separators fails to install in WordPress.
  if (entry.includes(".")) zip.file(path, { name: `${slug}/${entry}` });
  else zip.directory(path, `${slug}/${entry}`);
}

await zip.finalize();
output.on("close", () => console.log(`${target} (${zip.pointer()} bytes)`));
