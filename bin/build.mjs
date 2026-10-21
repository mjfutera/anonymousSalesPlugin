// Builds the release folder and zip for the WordPress.org directory:
//   node bin/build.mjs            -> dist/mfpro-anonymous-digital-sales/ and dist/mfpro-anonymous-digital-sales.zip
// Everything listed in .distignore stays out (git files, bin/, dist/, .wordpress-org/ ...).
// The folder is named after the plugin slug, the main file keeps its name.
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const slug = 'mfpro-anonymous-digital-sales'
const out = path.join(root, 'dist', slug)
const zip = path.join(root, 'dist', `${slug}.zip`)

const ignore = readFileSync(path.join(root, '.distignore'), 'utf8')
  .split(/\r?\n/)
  .map((line) => line.trim())
  .filter((line) => line && !line.startsWith('#'))

const matches = (name) =>
  ignore.some((pattern) => (pattern.startsWith('*') ? name.endsWith(pattern.slice(1)) : name === pattern))

rmSync(path.join(root, 'dist'), { recursive: true, force: true })
mkdirSync(out, { recursive: true })

// (cpSync cannot copy a folder into its own subfolder, so the tree is walked by hand)
function copyTree(from, to) {
  for (const entry of readdirSync(from, { withFileTypes: true })) {
    if (matches(entry.name)) continue
    const source = path.join(from, entry.name)
    const target = path.join(to, entry.name)
    if (entry.isDirectory()) {
      mkdirSync(target, { recursive: true })
      copyTree(source, target)
    } else {
      cpSync(source, target)
    }
  }
}
copyTree(root, out)

// zip with the folder at the top level (what WordPress expects)
// Windows: bsdtar (System32) writes zip files; the tar of Git Bash does not
const tar = process.platform === 'win32' ? path.join(process.env.SystemRoot || 'C:/Windows', 'System32', 'tar.exe') : 'tar'
const result = spawnSync(tar, ['-a', '-c', '-f', `${slug}.zip`, slug], { cwd: path.join(root, 'dist'), stdio: 'inherit' })
if (result.status !== 0 || !existsSync(zip)) {
  console.error('zip failed (needs tar with zip support, e.g. bsdtar on Windows)')
  process.exit(1)
}
console.log(`built ${path.relative(root, out)} and ${path.relative(root, zip)}`)
