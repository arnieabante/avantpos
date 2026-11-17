/**
 * Runs Vitest with a normalised working directory.
 *
 * On Windows, a lowercase drive letter in the cwd ("d:\xampp\...", which is how
 * VS Code terminals often open this folder) makes Vitest load two copies of
 * itself, and every `import { it } from 'vitest'` fails with
 * "Vitest failed to find the current suite". Upper-casing the drive fixes it.
 */
import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import path from 'node:path'

const cwd = process.cwd().replace(/^[a-z]:/, (drive) => drive.toUpperCase())
const require = createRequire(path.join(cwd, 'package.json'))
const vitestBin = path.join(path.dirname(require.resolve('vitest/package.json')), 'vitest.mjs')

const result = spawnSync(process.execPath, [vitestBin, ...process.argv.slice(2)], { cwd, stdio: 'inherit' })
process.exit(result.status ?? 1)
