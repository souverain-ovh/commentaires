/* Souverain.ovh */
import { build } from "esbuild";
import { readFile, writeFile, readdir } from "node:fs/promises";
import { createHash } from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const result = await build({
    absWorkingDir: root,
    entryPoints: ["src/atproto/entry.js"],
    bundle: true,
    platform: "browser",
    format: "iife",
    target: "es2022",
    minify: true,
    banner: { js: "/* Souverain.ovh */" },
    metafile: true,
    outfile: "atproto-oauth.js"
});

const packageNames = new Set();
for (const input of Object.keys(result.metafile.inputs)) {
    const parts = input.split("/");
    if (parts[0] !== "node_modules") continue;
    packageNames.add(parts[1].startsWith("@") ? parts.slice(1, 3).join("/") : parts[1]);
}

const dependencies = [];
const notices = [
    "Bibliothèques intégrées dans atproto-oauth.js",
    "==========================================",
    "",
    "Ces textes proviennent des paquets npm utilisés pour construire le bundle.",
    "Ils conservent leurs propres licences ; la licence 0BSD du projet ne les remplace pas.",
    "Conserver ce fichier lors de la redistribution du bundle.",
    ""
];

for (const name of [...packageNames].sort()) {
    const directory = path.join(root, "node_modules", name);
    const info = JSON.parse(await readFile(path.join(directory, "package.json"), "utf8"));
    const files = (await readdir(directory)).filter(name => /^(licen[cs]e|copying|notice|copyright)(\.|$)/i.test(name)).sort();
    if (!files.length) throw new Error(`Aucune notice de licence trouvée pour ${name}.`);
    dependencies.push({ name, version: info.version, license: info.license ?? "Voir notice" });
    notices.push("------------------------------------------------------------", `${name} ${info.version}`, `Licence déclarée : ${info.license ?? "voir texte"}`, "");
    for (const file of files) {
        notices.push(`[${file}]`, (await readFile(path.join(directory, file), "utf8")).trimEnd(), "");
    }
}

const bundle = await readFile(path.join(root, "atproto-oauth.js"));
const project = JSON.parse(await readFile(path.join(root, "package.json"), "utf8"));
await writeFile(path.join(root, "THIRD-PARTY-NOTICES.txt"), notices.join("\n") + "\n");
await writeFile(path.join(root, "BUILD-INFO.json"), JSON.stringify({
    version: project.version,
    bundle: "atproto-oauth.js",
    sha256: createHash("sha256").update(bundle).digest("hex"),
    bytes: bundle.length,
    dependencies
}, null, 2) + "\n");
console.log(`Bundle construit ; notices de ${dependencies.length} bibliothèques conservées.`);
