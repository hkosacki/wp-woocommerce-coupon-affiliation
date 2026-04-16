#!/usr/bin/env node
/**
 * Production ZIP for WordPress: wp-woocommerce-coupon-affiliation.zip
 *
 * @package WooCommerce_Coupon_Affiliation
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

let archiver;
try {
	archiver = require( 'archiver' );
} catch ( err ) {
	console.error(
		'[build-zip] Missing dependency: archiver.\n' +
			'  Run: npm install\n' +
			'  Or:  npm install archiver --save-dev'
	);
	process.exit( 1 );
}

const ROOT = __dirname;
const PLUGIN_SLUG = 'wp-woocommerce-coupon-affiliation';
const ZIP_NAME = `${ PLUGIN_SLUG }.zip`;
const PREFIX = `${ PLUGIN_SLUG }/`;

const SKIP_DIR_NAMES = new Set( [ 'node_modules', '.git' ] );

const SKIP_FILES = new Set(
	[
		ZIP_NAME,
		'build-zip.js',
		'package.json',
		'package-lock.json',
		'.wp-env.json',
		'.wp-env.override.json',
	] );

const PLUGIN_DIR_PREFIXES = [ 'includes/', 'admin/', 'assets/', 'templates/' ];

/**
 * @param {string} relPosix Relative path with forward slashes.
 */
function pathHasSkipSegment( relPosix ) {
	const parts = relPosix.split( '/' );
	return parts.some( ( p ) => SKIP_DIR_NAMES.has( p ) );
}

/**
 * @param {string} relPosix Relative path with forward slashes.
 */
function shouldSkipFile( relPosix ) {
	const base = relPosix.includes( '/' ) ? relPosix.slice( relPosix.lastIndexOf( '/' ) + 1 ) : relPosix;
	if ( SKIP_FILES.has( base ) || SKIP_FILES.has( relPosix ) ) {
		return true;
	}
	return false;
}

/**
 * @param {string} relPosix Relative path with forward slashes.
 */
function shouldInclude( relPosix ) {
	if ( pathHasSkipSegment( relPosix ) || shouldSkipFile( relPosix ) ) {
		return false;
	}
	if ( relPosix === 'README.md' ) {
		return true;
	}
	if ( relPosix.endsWith( '.php' ) ) {
		return true;
	}
	for ( const prefix of PLUGIN_DIR_PREFIXES ) {
		if ( relPosix.startsWith( prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * @return {string[]} Relative POSIX paths from ROOT.
 */
function collectFiles() {
	/** @type {string[]} */
	const out = [];

	/**
	 * @param {string} dirAbs
	 * @param {string} dirRelPosix
	 */
	function walk( dirAbs, dirRelPosix ) {
		let entries;
		try {
			entries = fs.readdirSync( dirAbs, { withFileTypes: true } );
		} catch {
			return;
		}
		for ( const ent of entries ) {
			const name = ent.name;
			if ( ent.isDirectory() ) {
				if ( SKIP_DIR_NAMES.has( name ) ) {
					continue;
				}
				const childRel = dirRelPosix ? `${ dirRelPosix }/${ name }` : name;
				if ( pathHasSkipSegment( childRel ) ) {
					continue;
				}
				walk( path.join( dirAbs, name ), childRel );
				continue;
			}
			if ( ! ent.isFile() ) {
				continue;
			}
			const fileRel = dirRelPosix ? `${ dirRelPosix }/${ name }` : name;
			const posix = fileRel.split( path.sep ).join( '/' );
			if ( shouldInclude( posix ) ) {
				out.push( posix );
			}
		}
	}

	walk( ROOT, '' );
	out.sort();
	return out;
}

async function main() {
	const relFiles = collectFiles();
	if ( relFiles.length === 0 ) {
		console.error( '[build-zip] No files matched include rules.' );
		process.exit( 1 );
	}

	const zipPath = path.join( ROOT, ZIP_NAME );
	const output = fs.createWriteStream( zipPath );
	const archive = archiver( 'zip', { zlib: { level: 9 } } );

	const done = new Promise( ( resolve, reject ) => {
		output.on( 'close', resolve );
		output.on( 'error', reject );
		archive.on( 'error', reject );
	} );

	archive.pipe( output );

	for ( const rel of relFiles ) {
		const abs = path.join( ROOT, ...rel.split( '/' ) );
		archive.file( abs, { name: PREFIX + rel } );
	}

	await archive.finalize();
	await done;

	console.log(
		`[build-zip] Wrote ${ ZIP_NAME } (${ archive.pointer() } bytes, ${ relFiles.length } files).`
	);
}

main().catch( ( err ) => {
	console.error( '[build-zip] Failed:', err );
	process.exit( 1 );
} );
