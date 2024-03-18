#!/usr/bin/env php
<?php
// phpcs:disable Generic.Arrays.DisallowLongArraySyntax.Found
// phpcs:disable Generic.ControlStructures.InlineControlStructure.NotAllowed
// phpcs:disable Generic.Files.LineLength.TooLong
// phpcs:disable Generic.Formatting.DisallowMultipleStatements.SameLine
// phpcs:disable MediaWiki.ControlStructures.AssignmentInControlStructures.AssignmentInControlStructures
// phpcs:disable MediaWiki.Usage.StaticClosure.StaticClosure
// phpcs:disable MediaWiki.VariableAnalysis.UnusedGlobalVariables.UnusedGlobal$includedFilenames
// phpcs:disable MediaWiki.WhiteSpace.SpaceAfterClosure.NoWhitespaceAfterClosure
// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeControlStructureBrace.BraceOnNewLine
// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeControlStructureBrace.SpaceBeforeControl
// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment
// phpcs:disable MediaWiki.WhiteSpace.SpaceyParenthesis.SingleSpaceAfterOpenParenthesis
// phpcs:disable MediaWiki.WhiteSpace.SpaceyParenthesis.SingleSpaceBeforeCloseParenthesis
// phpcs:disable PSR2.ControlStructures.ElseIfDeclaration.NotAllowed
// phpcs:disable PSR2.Files.EndFileNewline.TooMany
// phpcs:disable Squiz.Functions.FunctionDeclarationArgumentSpacing.NoSpaceBeforeArg
// phpcs:disable Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen
// phpcs:disable Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose
// phpcs:disable Squiz.WhiteSpace.FunctionSpacing.Before
// phpcs:disable Squiz.WhiteSpace.ScopeClosingBrace.ContentBefore

/**
 * This is a generic code for checking php files for entry points.
 * Most php apps have a few entry points and the other files are loaded
 * by the framework as needed. Thus it is important that directly calling
 * them doesn't allow to run unintended code.
 *
 * The rules taken for a file safety are the following:
 *  - Any code inside a class is safe.
 *  - Any code inside a function is safe.
 *  - Any code after a structure like if (!defined(...)) { ... die() } is safe.
 *  - Operating with variables in the global space is safe (language files, extensions setting up hooks...)
 *  - Call of functions non-whitelisted in the global space is unsafe.
 *  - includes or requires in the global space are unsafe if we cannot safely recurse (specially if they use variables in getting the path...).
 *
 */

ini_set( "memory_limit", -1 );

$whitelistedFunctions = array( 'define', 'defined', 'dirname', 'function_exists', 'class_exists', 'php_sapi_name', 'version_compare', 'phpversion', 'getcwd' );

function debug( $msg ) {
	global $debug;
	if ( $debug ) {
		echo "$msg\n";
	}
}

function getLastSignificantToken( $tokens, $i, $howold = 1 ) {
	for ( $i--; $i >= 0; $i-- ) {
		if ( ( strpos( '(&', $tokens[$i][0] ) === false ) && !in_array( $tokens[$i][0], array( T_WHITESPACE, T_COMMENT ) ) ) {
			if ( !--$howold )
				return $tokens[$i];
		}
	}
	return array( null );
}

/**
 * Reads $tokens indexed by $i, leaving the resulting folder in $resultdir
 * - Returns true if it advanced some folder.
 * - Returns false if it didn't match the expected tokens.
 * - Returns null if it didn't change $resultdir
 */
function advanceDirnames($tokens, &$i, &$resultdir) {
	if ( $tokens[$i][0] == T_FILE ) {
		$resultdir = $resultdir;
	} elseif ( $tokens[$i][0] == T_DIR ) {
		$resultdir = dirname( $resultdir );
	} elseif ( ( $tokens[$i][0] == T_STRING ) && $tokens[$i][1] == 'dirname' ) {
		do {
			$i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != '(' ) return false;
		do {
			$i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		$resultdir = dirname( $resultdir );

		if ( !advanceDirnames($tokens, $i, $resultdir) )
			return false;

		do {
			$i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != ')' ) return false;
	} else {
		return null;
	}
	return true;
}

$includedFilenames = array();
/**
 * Return the filename being included or false
 */
function getIncludeFilename( $currentFilename, $tokens, $i ) {
	# Parses the /[ (]*(dirname *\( *__FILE__ *\) *)?T_CONSTANT_ENCAPSED_STRING[) ]*;/ regex
	global $includedFilenames;

	while ( ( $tokens[$i] == '(' ) || ( $tokens[$i][0] == T_WHITESPACE ) ) {
		$i++;
	}

	$absolute = $currentFilename;
	$advanced = advanceDirnames( $tokens, $i, $absolute );
	if ( $advanced ) {
		do {
			$i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != '.' ) return false;
		do {
			$i++; } while ( $tokens[$i][0] == T_WHITESPACE );
	} elseif ( $advanced === false ) {
		return false;
	} else {
		$absolute = false;
	}

	$filetoken = $tokens[$i];
	if ( ( $filetoken[0] == T_STRING ) && ( $filetoken[1] == 'DO_MAINTENANCE' || $filetoken[1] == 'RUN_MAINTENANCE_IF_MAIN'  ) ) {
		// Hack for MediaWiki maintenance
		foreach ( $includedFilenames as $lastFilename ) {
			if ( substr( $lastFilename[1], -16 ) == '/Maintenance.php' ) {
				$filetoken[1] = "'" . str_replace( 'Maintenance.php', 'doMaintenance.php', $lastFilename[1] ) . "'"; # It will be treated as clean for the wrong way, but the final result is right.
				$absolute = $lastFilename[0];
				break;
			}
		}

		if ( !$absolute ) {
			return false;
		}
	} else if ( $filetoken[0] != T_CONSTANT_ENCAPSED_STRING ) {
		return false;
	}

	do {
		$i++;
	} while ( ( $tokens[$i] == ')' ) || ( $tokens[$i][0] == T_WHITESPACE ) );

	if ( $tokens[$i] != ';' )
		return false;

	$filename = substr( $filetoken[1], 1, -1 );
	if ( strpos( $filename, '\\' ) !== false ) {
		if ( $filetoken[1][0] === "'" ) {
			$filename = strtr( $filename, array( "\\\\" => "\\", "\\'" => "'" ) );
		} else {
			return false;
		}
	}

	$includedFilenames[] = array( $absolute, $filename );

	if ( $absolute === false && ( $filename[0] == '/' || ( substr(PHP_OS, 0, 3) == 'WIN' && substr( $filename, 1, 3 ) == ':\\\\' ) ) ) {
		$absolute = "";
	}

	if ( $absolute === false ) {
		$resolvedFilename = stream_resolve_include_path( $filename );
		if ( $resolvedFilename !== false ) {
			return $resolvedFilename;
		}
		$absolute = dirname( $currentFilename );
	}

	return $absolute . '/' . $filename;
}

/**
 * Returns if the analysis of a file is suitable to be cached.
 * For MediaWiki, maintenance scripts (including Benchmarks) may be
 * including Maintenance.php through several files and thus we are
 * not caching them.
 */
function cacheableAnalysis( $path ) {
	return strpos( $path, 'maintenance' ) === false;
}

function isEntryPoint( $file ) {
	static $evaluatedFiles = array();
	global $whitelistedFunctions, $includedFilenames;

	$rpath = realpath( $file );
	if ( isset( $evaluatedFiles[$rpath] ) && cacheableAnalysis( $rpath ) ) {
		return $evaluatedFiles[$rpath];
	}
	$evaluatedFiles[$rpath] = true;

	$braces = 0;
	$safeBraces = 0;
	$definedAutomaton = token_get_all( "<?php if(!defined('constant_name')){" ); # TODO: Rob Church does extensions the other way
	$cliSapiAutomatons = array();
	$cliSapiAutomatons[] = token_get_all( "<?php if(php_sapi_name()!='cli'){" );
	$cliSapiAutomatons[] = token_get_all( "<?php if(PHP_SAPI!='cli'){" );
	$cliSapiAutomatons[] = token_get_all( "<?php if(PHP_SAPI!=='cli'){" );
	$cliSapiAutomatons[] = token_get_all( "<?php if(PHP_SAPI!='cli-server'){" );
	array_shift( $definedAutomaton ); array_walk($cliSapiAutomatons, function(&$array, $key) { array_shift($array); });
	$definedAutomatonState = 0; $cliSapiAutomatonsState = str_split( str_repeat( '0', count( $cliSapiAutomatons ) ) );
	$inDefinedConditional = false;
	$mustDieOnThisSection = false;
	$contents = file_get_contents( $file );
	if ( $contents === false ) {
		debug( "Couldn't open file $file" );
		return true; # Something went wrong
	}
	$tokens = token_get_all( $contents );

	for ( $i = 0; $i < count( $tokens ); $i++ ) {
		if ( !$braces ) {
			if ( $tokens[$i][0] != T_WHITESPACE ) {
				if ( ( $tokens[$i] == $definedAutomaton[$definedAutomatonState] ) ||
					 ( ( $tokens[$i][0] == $definedAutomaton[$definedAutomatonState][0] ) )
					 && ( ( $tokens[$i][1] == $definedAutomaton[$definedAutomatonState][1] )
					 || ( $tokens[$i][0] == T_CONSTANT_ENCAPSED_STRING ) ) ) {
					$definedAutomatonState++;
					if ( $definedAutomatonState >= count( $definedAutomaton ) ) {
						$inDefinedConditional = true;
						$definedAutomatonState = 0;
					}
				} else {
					$definedAutomatonState = 0;
				}

				for ($j = 0; $j < count( $cliSapiAutomatons ); $j++) {
					if ( ( $tokens[$i] == $cliSapiAutomatons[$j][$cliSapiAutomatonsState[$j]] ) ||
						 ( ( $tokens[$i][0] == $cliSapiAutomatons[$j][$cliSapiAutomatonsState[$j]][0] )
						 && ( $tokens[$i][1] == $cliSapiAutomatons[$j][$cliSapiAutomatonsState[$j]][1] ) ) ) {
						$cliSapiAutomatonsState[$j]++;
						if ( $cliSapiAutomatonsState[$j] >= count( $cliSapiAutomatons[$j] ) ) {
							$inDefinedConditional = true;
							$cliSapiAutomatonsState[$j] = 0;
						}
					} else {
						$cliSapiAutomatonsState[$j] = 0;
					}
				}
			}
		}

		if ( $tokens[$i] == '{' ) {
			$braces++;
		} elseif ( $tokens[$i] == '}' ) {
			if ( $mustDieOnThisSection ) {
				debug( $mustDieOnThisSection );
				return true;
			}
			$braces--;
			if ( $braces < $safeBraces ) {
				$safeBraces = 0;
			}
		} elseif ( ( $tokens[$i][0] == T_CURLY_OPEN ) || ( $tokens[$i][0] == T_DOLLAR_OPEN_CURLY_BRACES ) ) {
			$braces++;
		}

		if ( $braces < $safeBraces || !$safeBraces ) {
			if ( $tokens[$i][0] == T_CLONE ) {
				debug( "$file clones a class in line {$tokens[$i][2]}" );
				return true;
			} elseif ( $tokens[$i][0] == T_EVAL ) {
				debug( "$file executes an eval() in line {$tokens[$i][2]}" );
				return true;
			} elseif ( in_array( $tokens[$i][0], array( T_ECHO, T_PRINT ) ) ) {
				if ( $inDefinedConditional ) {
					/* Allow the echo if this file dies inside this if*/
					if ( !$mustDieOnThisSection ) {
						$mustDieOnThisSection = "$file uses {$tokens[$i][1]} in line {$tokens[$i][2]}";
					}
				} else {
					debug( "$file uses {$tokens[$i][1]} in line {$tokens[$i][2]}" );
					return true;
				}
			} elseif ( $tokens[$i][0] == T_GOTO ) {
				# This bypass our check
				debug( "$file uses goto in line {$tokens[$i][2]}" );
				return true;
			} elseif ( $tokens[$i][0] == T_STRING ) {
				$lastToken = getLastSignificantToken( $tokens, $i );
				if ( in_array( $lastToken[0], array( T_CLASS, T_EXTENDS, T_FUNCTION, T_IMPLEMENTS, T_INTERFACE ) ) ) {
					$safeBraces = $braces + 1;
				}
			} elseif ( in_array( $tokens[$i][0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ) ) ) {
				$filename = getIncludeFilename( $rpath, $tokens, $i + 1 );

				if ( !$filename || $iep = isEntryPoint( $filename ) ) {
					debug( "$file {$tokens[$i][1]}s another file in line {$tokens[$i][2]}" );
					return true;
				}
				if ( $iep === null ) {
					// There won't be any web access to this file below the include
					debug( "$file {$tokens[$i][1]}s $filename in line {$tokens[$i][2]} and it ends the request" );
					$evaluatedFiles[$rpath] = null;
					return null;
				}
			} elseif ( $tokens[$i][0] == T_INLINE_HTML ) {
				if ( $inDefinedConditional ) {
					/* Allow the echo if this file dies inside this if*/
					if ( !$mustDieOnThisSection ) {
						$mustDieOnThisSection = "$file outputs html in line {$tokens[$i][2]}";
					}
				} elseif ( $i != 0 || $tokens[$i][1] != "#!/usr/bin/env php\n" ) {
					debug( "$file outputs html in line {$tokens[$i][2]}" );
					return true;
				}
			} elseif ( $tokens[$i][0] == T_NEW ) {
				debug( "$file creates a new object in line {$tokens[$i][2]}" );
				return true;
			} elseif ( $tokens[$i][0] == T_OPEN_TAG_WITH_ECHO ) {
				debug( "$file echoes with $tokens[$i][1] in line {$tokens[$i][2]}" );
				return true;
			} elseif ( in_array( $tokens[$i][0], array( T_RETURN, T_EXIT ) ) ) {
				if ( !$braces || $inDefinedConditional ) {
					debug( "$file ends its processing with a {$tokens[$i][1]} in line {$tokens[$i][2]}" );
					$evaluatedFiles[$rpath] = null;
					return null;
				}
			} elseif ( $tokens[$i] == '(' ) {
				$lastToken = getLastSignificantToken( $tokens, $i );
				if ( $lastToken[0] == T_VARIABLE ) {
					debug( "$file calls a variable function in line $lastToken[2]" );
					return true;
				} elseif ( $lastToken[0] == T_STRING ) {
					$prev = getLastSignificantToken( $tokens, $i, 2 );
					if ( $prev[0] == T_FUNCTION ) {
						# Function definition
					} else {
						# Function call
						if ( !in_array( $lastToken[1], $whitelistedFunctions ) ) {
							debug( "$file calls function $lastToken[1]() in line $lastToken[2]" );
							return true;
						}
					}
				}
			}
		}

	}
	$evaluatedFiles[$rpath] = false;
	return false;
}

$verbose = false;
$debug = false;
$entries = 0;
$total = 0;

array_shift( $argv );
if ( ( $argv[0] == '--verbose' ) || ( $argv[0] == '-v' ) ) {
	$verbose = true;
	array_shift( $argv );
}

if ( ( $argv[0] == '--debug' ) || ( $argv[0] == '-d' ) ) {
	$debug = true;
	array_shift( $argv );
}

if ( substr( $argv[0], 0, 8 ) == '--allow=' ) {
	$whitelistedFunctions = array_merge( $whitelistedFunctions, explode( ',', substr( $argv[0], 8 ) ) );
	array_shift( $argv );
}

foreach ( $argv as $arg ) {
	$includedFilenames = array();
	if ( isEntryPoint( $arg ) ) {
		$entries++;
		echo "$arg is an entry point\n";
	} else if ( $verbose ) {
		echo "$arg is not an entry point\n";
	}
	$total++;
}
echo "$entries/$total\n";

