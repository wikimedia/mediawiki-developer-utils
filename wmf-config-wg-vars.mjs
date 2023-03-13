/**
 * wmf-config-wg-vars.js: Find bad wg-variable references in wmf-config.
 *
 * See also: <https://wikitech.wikimedia.org/wiki/Technical_debt/Unused_config>
 *
 * Requires Node 14 or later.
 *
 * Usage:
 *
 * ```
 * # Update your clone of gerrit:operations/mediawiki-config.git
 * you$ cd /path/to/operations/mediawiki-config
 * you:mediawiki-config$ git pull
 *
 * # Dump matches
 * you$ cd code-utils/
 * you:code-utils$ ack "[$'\"]wg[A-Z]" --type=php /path/to/operations/mediawiki-config > wgvars.log
 *
 * # - Enter isolated environment (e.g. using Fresh)
 * # - install deps
 * # - run this script
 * you:code-utils$ fresh-node
 * nobody:code-utils$ npm install node-fetch@3
 * nobody:code-utils$ node wmf-config-wg-vars.mjs
 * ```
 */

import * as fs from 'fs';
import * as querystring from 'querystring';

import fetch from 'node-fetch';

const lines = fs.readFileSync('./wgvars.log').toString().split('\n');
const variables = Object.create(null);
const unusedVariables = Object.create(null);

const HTTP_CONCURRENCY = 10;

// Collect variable names
lines.forEach( line => {
	const fileName = line.split(':', 1)[0]
		// Strip personal directory path, e.g. if ack was run from code-utils
		.replace(/^.*mediawiki-config\//, '')
		// Strip common wmf-config/ prefix for brevity in result table output
		.replace(/^.*wmf-config\//, '');

	if (fileName.slice(-4) !== '.php' || fileName.startsWith('tests/')) {
		// Skip .html, .xml, including those in gitignore'd artefacts
		// Skip test files
		return;
	}
	const rVar = /[$'"]wg([A-Za-z0-9_]+)/g;
	let match;
	while ((match = rVar.exec(line)) !== null) {
		const varName = match[1];
		if (!variables[varName]) {
			variables[varName] = {
				name: varName,
				req: null,
				files: new Set()
			};
		}
		variables[varName].files.add(fileName);
	}
} );

// Convert to plain array for easy splice-based batching
const varQueue = Object.values(variables).sort( ( a, b ) => a.name > b.name ? 1 : -1 );
// console.log(Object.keys(variables));

// Search, find, and reduce!
(async function () {
	console.log('Searching for ' + varQueue.length + ' unique variable names...');
	const headers = {
		'User-Agent': 'wmf-config-wg-vars.js Bot; <https://gerrit.wikimedia.org/g/mediawiki/tools/code-utils>'
	};
	while (varQueue.length) {
		const varBatch = varQueue.splice(0, HTTP_CONCURRENCY);

		// Start the fetches in parallel to speed things up (no `await` here!)
		for (const variable of varBatch) {
			console.log('... fetching results for ' + variable.name);
			variable.req = fetch(
				'https://codesearch-backend.wmcloud.org/deployed/api/v1/search?'
					+ querystring.stringify({
						repos: '*',
						rng: ':20',
						q: '(\'|"|wg)' + variable.name,
						files: '',
						excludeFiles: 'HISTORY',
						i: 'nope'
					}),
				{ headers: headers }
			);
		}

		// Wait for this batch to complete
		await Promise.all(varBatch.map(variable => variable.req));

		for (const variable of varBatch) {
			const resp = await variable.req;
			const data = await resp.json();
			// Results from Hound
			const result = data.Results;
			// Ignore self, the source of our analysis
			delete result['Wikimedia MediaWiki config'];
			if (!Object.keys(result).length) {
				unusedVariables[variable.name] = variable;
			}
		}
	}

	const sortedKeys = Object.keys(unusedVariables).sort();
	// console.log(sortedKeys);

	// Start table
	console.log('{| class="wikitable sortable"');
	console.log('! Param !! Filename !! Component !! Status');
	sortedKeys.forEach( varName => {
		const files = Array.from(unusedVariables[varName].files);
		// Add table row
		console.log('|-');
		console.log('|<code>%s</code>', varName);
		console.log('|<code>%s</code>', files.join('</code>\n<code>'));
		console.log('|');
		console.log('|');
	} );
	// End table
	console.log('|}');
}());
