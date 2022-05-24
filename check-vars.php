#!/usr/bin/env php
<?php
// phpcs:disable Generic.Arrays.DisallowLongArraySyntax.Found
// phpcs:disable Generic.ControlStructures.InlineControlStructure.NotAllowed
// phpcs:disable Generic.Files.LineLength.TooLong
// phpcs:disable Generic.Formatting.SpaceAfterNot.Incorrect
// phpcs:disable Generic.Functions.FunctionCallArgumentSpacing.SpaceBeforeComma
// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged
// phpcs:disable MediaWiki.Commenting.FunctionComment.SpacingAfterParamName
// phpcs:disable MediaWiki.Commenting.FunctionComment.SpacingAfterReturnType
// phpcs:disable MediaWiki.Commenting.FunctionComment.WrongStyle
// phpcs:disable MediaWiki.Commenting.PropertyDocumentation.MissingDocumentationPublic
// phpcs:disable MediaWiki.ControlStructures.AssignmentInControlStructures.AssignmentInControlStructures
// phpcs:disable MediaWiki.ControlStructures.IfElseStructure.SpaceAfterElse
// phpcs:disable MediaWiki.ControlStructures.IfElseStructure.SpaceBeforeElse
// phpcs:disable MediaWiki.ExtraCharacters.ParenthesesAroundKeyword.ParenthesesAroundKeywords
// phpcs:disable MediaWiki.Usage.DirUsage.FunctionFound
// phpcs:disable MediaWiki.Usage.IsNull.IsNull
// phpcs:disable MediaWiki.WhiteSpace.MultipleEmptyLines.MultipleEmptyLines
// phpcs:disable MediaWiki.WhiteSpace.OpeningKeywordParenthesis.WrongWhitespaceBeforeParenthesis
// phpcs:disable MediaWiki.WhiteSpace.SpaceAfterControlStructure.Incorrect
// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment
// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.SingleSpaceBeforeSingleLineComment
// phpcs:disable MediaWiki.WhiteSpace.SpaceyParenthesis.SingleSpaceAfterOpenParenthesis
// phpcs:disable MediaWiki.WhiteSpace.SpaceyParenthesis.SingleSpaceBeforeCloseParenthesis
// phpcs:disable MediaWiki.WhiteSpace.SpaceyParenthesis.SpaceBeforeOpeningParenthesis
// phpcs:disable MediaWiki.WhiteSpace.SpaceyParenthesis.UnnecessarySpaceBetweenParentheses
// phpcs:disable PSR12.Properties.ConstantVisibility.NotFound
// phpcs:disable PSR2.Classes.PropertyDeclaration.ScopeMissing
// phpcs:disable PSR2.Classes.PropertyDeclaration.VarUsed
// phpcs:disable PSR2.ControlStructures.ElseIfDeclaration.NotAllowed
// phpcs:disable PSR2.ControlStructures.SwitchDeclaration.TerminatingComment
// phpcs:disable Squiz.Classes.SelfMemberReference.NotUsed
// phpcs:disable Squiz.Scope.MemberVarScope.Missing
// phpcs:disable Squiz.Scope.MethodScope.Missing
// phpcs:disable Squiz.WhiteSpace.FunctionSpacing.Before
// phpcs:disable Squiz.WhiteSpace.SemicolonSpacing.Incorrect
// phpcs:disable Squiz.WhiteSpace.SuperfluousWhitespace.EndLine


/*
 * Checks a number of syntax conventions on variables from a valid PHP file.
 *
 * Run as:
 *  find core/ \( -name \*.php -or -name \*.inc \) -not \( -name diffLanguage.php -o -name LocalSettings.php -o -name Parser?????.php \) -exec php tools/code-utils/check-vars.php \{\} +
 */
if ( ! $IP = getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = dirname( __FILE__ ) . "/../../core/";
}
$IP = rtrim( $IP, "/" );

if( !is_dir( $IP ) ) {
	print <<<EOF
Oops! Could not find MediaWiki Core.

Try prepending MW_INSTALL_PATH with a valid MediaWiki directory:

 MW_INSTALL_PATH=/path/to/core php {$argv[0]}

EOF;
	exit(1);
}


require_once( "$IP/includes/Defines.php" ); # Faster than parsing
require_once( "$IP/includes/AutoLoader.php" );
$wgAutoloadClasses = &$wgAutoloadLocalClasses;
include_once( "$IP/tests/TestsAutoLoader.php" );
$wgAutoloadClasses['MWInit'] = "$IP/includes/Init.php";

$mwDeprecatedFunctions = false;
@include( dirname( __FILE__ ) . "/deprecated.functions" );
$mwParentClasses = array();
@include( dirname( __FILE__ ) . "/parent.classes" );

if ( !extension_loaded( 'sockets' ) ) dl( 'sockets.so' );
if ( !extension_loaded( 'PDO' ) ) dl( 'pdo.so' );
if ( !extension_loaded( 'zip' ) ) dl( 'zip.so' );
if ( !extension_loaded( 'intl' ) ) dl( 'intl.so' ); // Provides the Collator class, used in Collation.php

$wgAutoloadLocalClasses += array(
		'DBAccessError' => 'LBFactory',
		'Maintenance' => 'Maintenance.php',
		'MessageGroup' => 'Translate extension interface',
		'MessageGroups' => 'Translate extension',
		'PremadeMediawikiExtensionGroups' => 'Translate extension',
		'languages' => 'maintenance/language/languages.inc',
		'extensionLanguages' => 'maintenance/language/languages.inc',
		'MessageWriter' => 'maintenance/language/writeMessagesArray.inc',
		'tidy' => 'pecl tidy',
		'PEAR' => 'pear',
		'Normalizer' => 'pecl intl',
		'Mail' => 'pear Mail',
		'Mail_mime' => 'pear Mail',

		'UserDupes' => 'maintenance/userDupes.inc',
		'DeleteDefaultMessages' => 'maintenance/deleteDefaultMessages.php',
		'PopulateCategory' => 'maintenance/populateCategory.php',
		'PopulateParentId' => 'maintenance/populateParentId.php',
		'PopulateRevisionLength' => 'maintenance/populateRevisionLength.php',
		'PopulateLogSearch' => 'maintenance/populateLogSearch.php',
		'BaseDump' => 'maintenance/backupPrefetch.inc',
		'ExportProgressFilter' => 'maintenance/backup.inc'
	);

class CheckVars {
	var $mDebug = false;
	static $mDefaultSettingsGlobals = null;
	static $mRequireKnownClasses = array();
	static $mRequireKnownFunctions = array();
	static $mRequireKnownConstants = array();

	static $mKnownFileClassesDefault = array();
	static $mKnownFunctionsDefault = array();
	static $mConstantsDefault = array();

	# Tokens which finish the execution of the current function
	protected static $mExitTokens = array( T_RETURN, T_THROW, T_EXIT /* = exit + die */ );

	# Ignore constants with these prefixes:
	static $constantIgnorePrefixes = array( "PGSQL_", "OCI_", "SQLT_", "DB2_", "XMLREADER_", "SQLSRV_", "MCRYPT_" );
	# Ignore functions with these prefixes:
	static $functionIgnorePrefixes = array( "pg_", "oci_", "db2_", "sqlsrv_", "exif_", "fss_", "tidy_",
			"apc_", "eaccelerator_", "xcache_", "wincache_", "apache_", "xdiff_", "wikidiff2_", "parsekit_",
			"wddx_", "setproctitle", "utf8_", "normalizer_", "dba_", "pcntl_", "finfo_", "mime_content_type", "curl_",
			"openssl_", "mcrypt_",
			# GD and images functions:
			"imagecreatetruecolor", "imagecolorallocate", "imagecolortransparent", "imagealphablending",
			"imagecopyresized", "imagesx", "imagesy", "imagecopyresampled", "imagesavealpha",
			"imagedestroy", "imageinterlace", "imagejpeg",
			# readline is usualy not available since linking libreadline with PHP breaks GPL license
			"readline",
		);

	static $extensionFunctions = array(
		 # GlobalFunctions.php conditionally uses bcmath in wfBaseConvert since 9b9daad
		 # List at http://php.net/manual/en/book.bc.php
		'bcmath' => array( 'bcadd', 'bccomp', 'bcdiv', 'bcmod', 'bcmul', 'bcpow', 'bcpowmod', 'bcscale', 'bcsqrt', 'bcsub' ),
		# http://www.php.net/gmp
		'gmp' => array( 'gmp_abs', 'gmp_add', 'gmp_and', 'gmp_clrbit', 'gmp_cmp', 'gmp_com', 'gmp_div_q',
			'gmp_div_qr', 'gmp_div_r', 'gmp_div', 'gmp_divexact', 'gmp_fact', 'gmp_gcd', 'gmp_gcdext',
			'gmp_hamdist', 'gmp_init', 'gmp_intval', 'gmp_invert', 'gmp_jacobi', 'gmp_legendre',
			'gmp_mod', 'gmp_mul', 'gmp_neg', 'gmp_nextprime', 'gmp_or', 'gmp_perfect_square',
			'gmp_popcount', 'gmp_pow', 'gmp_powm', 'gmp_prob_prime', 'gmp_random', 'gmp_scan0',
			'gmp_scan1', 'gmp_setbit', 'gmp_sign', 'gmp_sqrt', 'gmp_sqrtrem', 'gmp_strval',
			'gmp_sub', 'gmp_testbit', 'gmp_xor' ),
	);

	# Functions to be avoided. Insert in lowercase.
	static $poisonedFunctions = array(
		'addslashes' => 'Replace with Database::addQuotes/strencode',
		'mysql_db_query' => 'Deprecated since PHP 5.3.0',
		'mysql_escape_string' => 'Replace with Database::addQuotes/strencode',
		'create_function' => 'create_function should be avoided. See http://www.mediawiki.org/wiki/Security_for_developers#Dynamic_code_generation',
		'eval' => 'eval should be avoided. See r78046', # eval.php is magically not listed for not containing any function. Should get an exception if it starts being parsed.
		'call_user_method' => 'Deprecated since PHP 4.1.0',
		'call_user_method_array' => 'Deprecated since PHP 4.1.0',
		'ereg' => 'Deprecated since PHP 5.3.0',
		'ereg_replace' => 'Deprecated since PHP 5.3.0',
		'eregi' => 'Deprecated since PHP 5.3.0',
		'eregi_replace' => 'Deprecated since PHP 5.3.0',
		'split' => 'Deprecated since PHP 5.3.0',
		'spliti' => 'Deprecated since PHP 5.3.0',
		'sql_regcase' => 'Deprecated since PHP 5.3.0',
		'set_socket_blocking' => 'Deprecated since PHP 5.3.0. Use stream_set_blocking()',
		'session_register' => 'Deprecated since PHP 5.3.0. Use $_SESSION directly',
		'session_unregister' => 'Deprecated since PHP 5.3.0.',
		'session_is_registered' => 'Deprecated since PHP 5.3.0.',
		'set_magic_quotes_runtime' => 'Deprecated since PHP 5.3.0.',

		'var_dump' => 'Debugging function.', // r81671#c13996
		// 'print_r' => 'Debugging function if second parameter is not true.',
		'wfVarDump' => 'Debugging function.', // var_export() wrapper
		);

	static $enabledWarnings = array(
		'utf8-bom' => true,
		'php-trail' => true,
		'double-php-open' => true,
		'double-;' => true,
		'this-in-static' => true,
		'missed-docblock' => false,
		'profileout' => false,
		'profileout-throw' => false,
		'matchingprofiles' => true,
		'matchingprofiles-throw' => false, // Expect to run the wfProfileOut() before throwing an exception
		'evil-@' => false,
		'global-in-switch' => true,
		'global-as-local' => true,
		'global-names' => true,
		'double-globals' => true,
		'unused-global' => true,
		'undefined-global' => true,
		'function-function' => true,
		'missing-function' => true,
		'missing-class' => true,
		'orphan-parent' => true,
		'$self' => true,
		'function-throw' => true,
		'undefined-constant' => true,
		'missing-requires' => true,
		'deprecated-calls' => true,
		'hidden-deprecated-calls' => false,
		'deprecated-might' => false, // Too many false positives. Reenable later.
		'poisoned-function' => true,
		'extension-not-loaded' => true,
		'internal-error' => true,
		'error' => true,
		# 'help' keyword is reserved!!
		);
	/** print out the default warnings list */
	static function dumpWarningsKeywords() {
		print "Warning keywords suitable for -W<[no]keyword>:\n";
		$w = CheckVars::$enabledWarnings ;
		asort( $w ); // sort by status
		print "Keywords disabled by default:\n";
		$prevStatus = false;
		foreach ( $w as $key => $status ) {
			if ( $status !== $prevStatus ) {
				$prevStatus = $status;
				print "Keywords enabled by default:\n";
			}
			print "\t$key\n";
		}
	}

	protected $generateDeprecatedList = false;
	protected $generateParentList = false;

	/* Values for status */
	const WAITING_FUNCTION = 0;
	const IN_FUNCTION_NAME = 1;
	const IN_FUNCTION = 2;
	const IN_GLOBAL = 3;
	const IN_INTERFACE = 4;
	const IN_REQUIRE_WAITING = 6;
	const IN_FUNCTION_REQUIRE = 8;
	const IN_FUNCTION_PARAMETERS = 9;

	/* Token specializations */
	const CLASS_NAME = -4;
	const CLASS_MEMBER = -5;
	const FUNCTION_NAME = -6;
	const FUNCTION_DEFINITION = -7;
	const INTERFACE_NAME = -9;

	/* Function attribute */
	const FUNCTION_DEPRECATED = -8;

	function __construct() {
		if ( self::$mDefaultSettingsGlobals == null ) {
			global $IP;
			$this->load( "$IP/includes/DefaultSettings.php", false );
			if ( count( $this->mTokens ) > 0 ) {
				$globals = array (
					'$wgAutoloadLocalClasses', # AutoLoader.php, a couple of readers
					'$wgCanonicalNamespaceNames', # Namespace.php
					'$wgContLang', # Setup.php
					'$wgDeferredUpdateList', # Setup.php
					'$wgExtModifiedFields', '$wgExtNewFields', '$wgExtNewIndexes', '$wgExtNewTables', # Updates
					'$wgFeedClasses', # Defines.php, many uses
					'$wgLang', # Setup.php
					'$wgLanguageNames', # Language.php, read by others
					'$wgMemc', # Setup.php
					'$wgMessageCache', # Setup.php
					'$wgLangConvMemc', # Setup.php
					'$wgNoDBParam', # maintenance, serialized
					'$wgOut', # Setup.php
					'$wgParser', # Setup.php
					'$wgPostCommitUpdateList', # Initialised in Setup.php, should be removed
					'$wgProfiler', # StartProfiler.php
					'$wgProfiling', # Profiler.php
					'$wgQueryPages', # QueryPage.php
					'$wgRequest', # Setup.php
					'$wgRequestTime', # WebStart.php
					'$wgTitle', # index.php
					'$wgUpdates', # updaters
					'$wgUseEnotif', # Setup.php
					'$wgUseNormalUser', # maintenance
					'$wgUser', # Setup.php
				);

				foreach ( $this->mTokens as $token ) {
					if ( is_array( $token ) && ( $token[0] == T_VARIABLE ) && ( substr( $token[1], 0, 3 ) == '$wg' ) ) {
						$globals[] = $token[1];
					}
				}
				self::$mDefaultSettingsGlobals = array_unique( $globals );
				$this->mTokens = array(); # Free
			}
		}
	}

	protected static $mGlobalsPerFile = array( # Variables which are OK, but only on a few files
			'$wgHtmlEntities' => array( 'Sanitizer.php' ),
			'$wgHtmlEntityAliases' => array( 'Sanitizer.php' ),
			'$wgFullyInitialised' => array( /* Set */ 'Setup.php', /* read */ 'Exception.php' ),
			'$wgContLanguageCode' => array( 'Setup.php' ),
			'$wgUseLatin1' => array( 'upgrade1_5.php' ), # If you upgrade from MW < 1.5 it will be there
			'$wgExtPGNewFields' => array( 'DatabaseUpdater.php', 'PostgresUpdater.php' ),
			'$wgExtPGAlteredFields' => array( 'DatabaseUpdater.php', 'PostgresUpdater.php' ),
			'$optionsWithArgs' => array( 'commandLine.inc' ),
			'$args' => array( 'commandLine.inc' ),
			'$options' => array( 'commandLine.inc', 'upgrade1_5.php' ),
			'$canonicalDecomp' => array( 'UtfNormalGenerate.php' ),
			'$compatibilityDecomp' => array( 'UtfNormalGenerate.php' ),
			'$mmfl' => array( 'mergeMessageFileList.php' ),
			'$checkBlacklist' => array( 'checkLanguage.inc' ),
			'$stderr' => array( 'serialize.php' ),
			'$col' => array( 'UtfNormalTest2.php' ),
			'$lineNo' => array( 'UtfNormalTest2.php' ),
			'$cliUpgrade' => array( 'CliInstaller.php' ),
			'$wgArticle' => array( 'Wiki.php' ),
			'$wgConfiguration' => array( 'Conf.php' ), # It's just an experiment for now
			'$sort' => array( 'profileinfo.php' ),
			'$filter' => array( 'profileinfo.php' ),
			'$expand' => array( 'profileinfo.php' ),
		);

	protected static $mExtraClassesPerFile = array( # Some files use extra classes provided by some extension
			'RandomImageGenerator.php' => array( 'Imagick', 'ImagickDraw', 'ImagickPixel' ), # Imagick extension
			'Bitmap.php' => array( 'Imagick', 'ImagickDraw', 'ImagickPixel', 'ImagickException' ), # One of many backends
			'SVG.php' => array( 'Imagick' ), # SvgHandler::rasterizeImagickExt, called from $wgSVGConverters
			
			'MemcachedPeclBagOStuff.php' => array( 'Memcached' ), // PECL Memcached extension
			'SwiftFileBackend.php' => array(  // Requires MW extension and php-cloudfiles library
				 # Defined in SwiftCloudFile extension:
				 #   SwiftCloudFiles/php-cloudfiles-wmf/cloudfiles_exceptions.php
				 # itself derived from upstream:
				 # https://github.com/rackspace/php-cloudfiles/blob/master/cloudfiles_exceptions.php
				'CloudFilesException', 'AuthenticationException', 'InvalidResponseException', 'NonEmptyContainerException',
				'NoSuchObjectException', 'NoSuchContainerException', 'NoSuchAccountException', 'MisMatchedChecksumException',
				'IOException', 'CDNNotEnabledException', 'BadContentTypeException', 'InvalidUTF8Exception', 'ConnectionNotOpenException',

				// Defined in https://github.com/rackspace/php-cloudfiles/blob/master/cloudfiles.php
				'CF_Authentication', 'CF_Connection', 'CF_Container', 'CF_Object',

				// Defined in mediawiki/extensions/SwiftCloudFiles/php-cloudfiles-wmf/cloudfiles_http.php
				'CF_Async_Op', 'CF_Async_Op_Batch'
				),
			
			'RedisBagOStuff.php' => array( 'Redis', 'RedisException' ),
			'JobQueueRedis.php' => array( 'Redis', 'RedisException' ),
			'RedisLockManager.php' => array( 'RedisException' ),
			'RedisConnectionPool.php' => array( 'Redis', 'RedisException' ),
			'JobQueueAggregatorRedis.php' => array( 'Redis', 'RedisException' ),
		);

	/* ApiBase has some profile methods */
	protected static $mMethodsSkippedProfileChecks = array( 'ApiBase::profileIn', 'ApiBase::profileDBIn', 'ApiBase::profileOut', 'ApiBase::profileDBOut' );

	function setGenerateDeprecatedList( $bool = true ) {
		$this->generateDeprecatedList = $bool;
	}

	function getGenerateDeprecatedList() {
		return $this->generateDeprecatedList;
	}

	function saveDeprecatedList( $filename ) {
		$data = "<?php\n\$mwDeprecatedFunctions = array(\n";
		foreach ( $this->mDeprecatedFunctionList as $depre => $classes ) {
			$data .= "\t'$depre' => array( '" . implode( "', '", $classes ) . "' ),\n";
		}
		$data .= "\n);\n\n";
		file_put_contents( $filename, $data );
	}

	function setGenerateParentList( $bool = true ) {
		$this->generateParentList = $bool;
	}

	function getGenerateParentList() {
		return $this->generateParentList;
	}

	function saveParentList( $filename ) {
		global $mwParentClasses;
		$data = "<?php\n\$mwParentClasses = array(\n";
		foreach ( $mwParentClasses as $class => $parent ) {
			$data .= "\t'$class' => '$parent' ,\n";
		}
		$data .= "\n);\n\n";
		file_put_contents( $filename, $data );
	}

	private function initVars() {
		$this->mProblemCount = 0;
		$this->mCallStack = array();
		$this->mProfileStack = array();
		$this->mProfileStackIndex = 0;
		$this->mConditionalProfileOutCount = 0;
		$this->anonymousFunction = false;
		$this->mExtensionFunctions = array();

		/* These are used even if it's shortcircuited */
		$this->mKnownFileClasses = self::$mKnownFileClassesDefault;
		$this->mUnknownClasses = array();
		$this->mUnknownFunctions = array();
		$this->mKnownFunctions = self::$mKnownFunctionsDefault;
		$this->mConstants = self::$mConstantsDefault;

		$this->mStatus = self::WAITING_FUNCTION;
		$this->mFunctionQualifiers = array();
		$this->mClass = null;
		$this->mParent = null;

		// Predefine constant that might not be defined by this file source code
		$this->mConstants = array( 'PARSEKIT_SIMPLE', 'UNORM_NFC', # Extensions
			/* Defined in Title.php and GlobalFunctions.php */
			'TC_MYSQL', 'TS_UNIX', 'TS_MW', 'TS_DB', 'TS_RFC2822',
			'TS_ISO_8601', 'TS_EXIF', 'TS_ORACLE', 'TS_POSTGRES', 'TS_DB2',
			'TS_ISO_8601_BASIC',
			/* PHP extensions */
			'FILEINFO_MIME', 'FILEINFO_MIME_TYPE', 'MHASH_ADLER32',
			'SIGTERM', 'SIG_DFL',
			'SVN_REVISION_HEAD', 'SVN_REVISION_INITIAL',
		) ;
	}

	function load( $file, $shortcircuit = true ) {
		$this->initVars();
		$this->mFilename = $file;

		$source = file_get_contents( $file );
		if ( substr( $source, 0, 3 ) == "\xEF\xBB\xBF" ) {
			$this->warning( 'utf8-bom', "$file has an UTF-8 BOM" );
		}
		$source = rtrim( $source );
		if ( substr( $source, -2 ) == '?>' ) {
			$this->warning( 'php-trail', "?> at end of file is deprecated in MediaWiki code" );
		}
		if ( $shortcircuit && !preg_match( "/^[^'\"#*]*function [^\"']*\$/m", $source ) ) {
			$this->mTokens = array();
			return;
		}

		/* Skip HipHop specific requires */
		$source = preg_replace( '/if \( isset\( \$_SERVER\[\'MW_COMPILED\'\] \) \) {\\s+require *\( \'core\/.*\' \);\\s+} else {/', 'if ( true ) {', $source );

		if ( basename( $file ) == 'User.php' ) {
			// The check for $row->user_options (removed in 1.19, eda06e859) guards the call to the deprecated User::decodeOptions()
			$source = preg_replace( '/if \( isset\( \$row->user_options \) \) \{\r?\n.*?\r?\n\t*\}/', "\n\n", $source );
		}
		if ( basename( $file ) == 'trackBlobs.php' && preg_match( '/if \( extension_loaded\( \'([^\']+)\' \) \) \{\r?\n\t*(\$this->[A-Za-z]+) = true;/', $source, $m ) ) {
			$source = preg_replace( '/if \( ' . preg_quote( $m[2] ) . ' \) /', "if ( " . $m[2] . " && extension_loaded( '" . $m[1] . "' ) ", $source );
		}

		if ( basename( $file ) == 'Preprocessor_Hash.php' ) {
			// Move the contents of the if ( $cacheable ) { checks at 594 and 602 to inline ifs, so the wfProfileOut() counter doesn't miss __METHOD__-cache-miss
			$source = preg_replace( '/(if \( \$cacheable \) {\r?\n)(\t*)(wfProfileOut\(.*\);\r?\n)((\t*)(wfProfileOut\(.*\);\r?\n))\t*}/', "\n$2if ( \$cacheable ) $3$5if ( \$cacheable ) $4", $source );
		}

		if ( basename( $file ) == 'Preprocessor_DOM.php' ) {
			// Move the second wfProfileOut() inside the if ( $cacheable ) { }, at line 172
			$source = preg_replace( '/(if \( \$cacheable \) {\r?\n\t*wfProfileOut\(.*\);\r?\n)(\t*}\r?\n)(\t*wfProfileOut\(.*\);\r?\n)/', "$1$3$2", $source );
		}

		$this->mTokens = token_get_all( $source );
		$this->queuedFunctions = array();
	}

	static $functionQualifiers = array( T_ABSTRACT, T_PRIVATE, T_PUBLIC, T_PROTECTED, T_STATIC );

	function execute() {
		global $IP;
		$currentToken = null;
		$runningQueuedFunctions = false;

		do {
			foreach ( $this->mTokens as $token ) {
				if ( self::isMeaningfulToken( $currentToken ) )
					$lastMeaningfulToken = $currentToken;
				$currentToken = $token;

				if ( $lastMeaningfulToken[0] == T_OPEN_TAG && $token[0] == T_OPEN_TAG ) {
					# See r69767
					$this->warning( 'double-php-open', "{$token[1]} in line {$token[2]} after {$lastMeaningfulToken[1]} in line {$lastMeaningfulToken[2]}" );
				}
				if ( $token == ';' ) {
					if ( $lastMeaningfulToken == ';' ) {
						# See r72751, warn on ;;
						$this->warning( 'double-;', "Empty statement" );
					} elseif ( $lastMeaningfulToken[0] == T_FOR ) {
						# But not on infinte for loops: for ( ; ; )
						$currentToken = array( ';', ';', $lastMeaningfulToken[2] );
					}
				}

				if ( $lastMeaningfulToken[0] == T_DECLARE && $token[0] == T_STRING ) {
					$currentToken[0] = T_WHITESPACE; # Ignore the ticks or encoding
					continue;
				}

				if ( is_array( $token ) && ( $token[0] == T_CONSTANT_ENCAPSED_STRING ) && is_array( $lastMeaningfulToken )
					&& ( ( $lastMeaningfulToken[0] == T_STRING ) || ( $lastMeaningfulToken[0] == self::FUNCTION_NAME ) )
					&& ( $lastMeaningfulToken[1] == 'define' ) ) {

					// Mark as defined
					$this->mConstants[] = trim( $token[1], "'\"" );
				}

				if ( is_array( $token ) && ( $token[0] == T_CONSTANT_ENCAPSED_STRING ) && is_array( $lastMeaningfulToken )
					&& ( ( $lastMeaningfulToken[0] == T_STRING ) || ( $lastMeaningfulToken[0] == self::FUNCTION_NAME ) )
					&& ( $lastMeaningfulToken[1] == 'defined' ) ) {

					// FIXME: Should be marked as defined only inside this T_IF
					$this->mConstants[] = trim( $token[1], "'\"" );
				}

				if ( $this->anonymousFunction ) {
					switch ( $this->anonymousFunction[0] ) {
						case 1: // After 'function'
							if ( $token[0] == '(' ) {
								$this->anonymousFunction[1] .= " (";
								$this->anonymousFunction[0] = 2;
							}
							break;
						case 2: // In function parameters
							$this->anonymousFunction[1] .= is_array( $token ) ? $token[1] : $token;
							if ( $token[0] == ')' ) {
								$this->anonymousFunction[0] = 3;
							}
							break;
						case 3: // After function parameters
							if ( $token[0] == T_USE ) {
								$this->anonymousFunction[0] = 4;
								$this->anonymousFunction[1] = rtrim( $this->anonymousFunction[1], ') ' );
							} elseif ( $token[0] == '{' ) {
								$this->anonymousFunction[0] = 5;
								$this->anonymousFunction[1] .= " {";
								$this->anonymousFunction[2] = 1;
							}
							break;
						case 4: // In USE
							if ( $token[0] != '(' ) {
								$this->anonymousFunction[1] .= is_array( $token ) ? $token[1] : $token;
							}
							if ( $token[0] == T_VARIABLE ) {
								// TODO: Check that it exists
							} elseif ( $token[0] == '{' ) {
								$this->anonymousFunction[0] = 5;
								$this->anonymousFunction[2] = 1;
							}
							break;
						case 5:
							$this->anonymousFunction[1] .= is_array( $token ) ? $token[1] : $token;
							if ( $token[0] == '{' || $token[0] == T_CURLY_OPEN || $token[0] == T_DOLLAR_OPEN_CURLY_BRACES ) {
								$this->anonymousFunction[2]++;
							} elseif ( $token[0] == '}' ) {
								$this->anonymousFunction[2]--;

								if ( $this->anonymousFunction[2] == 0 ) {
									$this->queuedFunctions[] = $this->anonymousFunction[1];
									$this->anonymousFunction = false;
								}
							}
							break;
					}

					continue;
				}

				switch ( $this->mStatus ) {
					case self::WAITING_FUNCTION:
						if ( $token == ';' )
							$this->mFunctionQualifiers = array();

						if ( $token[0] == T_COMMENT ) {
							if ( substr( $token[1], 0, 2 ) == '/*' && substr( $token[1], 0, 3 ) != '/**'
								&& preg_match( '/^\s+\*(?!\/)/m', $token[1] ) && strpos( $token[1], "\$separatorTransformTable = array( ',' => '' )" ) === false ) {
								$this->warning( 'missed-docblock', "Multiline comment with /* in line $token[2]" );
							}
						}

						if ( $token[0] == T_DOC_COMMENT ) {
							if ( strpos( $token[1], '@deprecated' ) !== false ) {
								$this->mFunctionQualifiers[] = self::FUNCTION_DEPRECATED;
							}
						}
						if ( in_array( $token[0], self::$functionQualifiers ) ) {
							$this->mFunctionQualifiers[] = $token[0];
						}
						if ( $token[0] == T_INTERFACE ) {
							$this->mStatus = self::IN_INTERFACE;
						}

						if ( ( $lastMeaningfulToken[0] == T_CLASS ) && ( $token[0] == T_STRING ) ) {
							$this->mKnownFileClasses[] = $token[1];
							$this->mClass = $token[1];
							$this->mParent = null;
						}

						if ( $token[0] == '}' ) {
							$this->mClass = null;
							$this->mParent = null;
						}

						if ( ( $lastMeaningfulToken[0] == T_EXTENDS ) && ( $token[0] == T_STRING ) ) {
							$this->checkClassName( $token );
							$this->mParent = $token[1];
							if ( $this->getGenerateParentList() ) {
								global $mwParentClasses;
								$mwParentClasses[ $this->mClass ] = $this->mParent;
							}
						}

						if ( in_array( $token[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ) ) ) {
							$this->mStatus = self::IN_REQUIRE_WAITING;
							$requirePath = "";
							continue;
						}

						if ( $token[0] != T_FUNCTION )
							continue;
						$this->mStatus = self::IN_FUNCTION_NAME;
						break;

					case self::IN_FUNCTION_NAME:
						if ( ( $token == '&' ) || ( $token[0] == T_WHITESPACE ) )
							continue;
						if ( $token[0] == T_STRING ) {
							$this->mFunction = $token[1];
							$this->mMethod = $this->mClass ? $this->mClass . "::" . $this->mFunction : $this->mFunction;
							$this->mStatus = self::IN_FUNCTION_PARAMETERS;
							$this->mBraces = 0;
							$this->mInSwitch = 0;
							$this->mInProfilingFunction = false;
							$this->mAfterProfileOut = 0;
							$this->mFunctionGlobals = array();
							$this->mLocalVariableTypes = array();
							$this->mHiddenDeprecatedCalls = array(); // Deprecated functions called which we should not warn about
							$currentToken[0] = self::FUNCTION_DEFINITION;
							$this->mKnownFunctions[] = $this->mMethod;

							if ( $this->generateDeprecatedList && in_array( self::FUNCTION_DEPRECATED, $this->mFunctionQualifiers ) ) {
								if ( ( substr( $this->mFunction, 0, 2 ) != "__" ) ) {
									if ( !isset( $this->mDeprecatedFunctionList[ $this->mFunction ] ) ) {
										$this->mDeprecatedFunctionList[ $this->mFunction ] = array( $this->mClass );
									} else {
										$this->mDeprecatedFunctionList[ $this->mFunction ][] = $this->mClass;
									}
								}
							}

							$this->debug( "Entering into function {$token[1]} at line {$token[2]} " );
							continue;
						}

						$this->error( $token );

					case self::IN_FUNCTION:
					case self::IN_FUNCTION_PARAMETERS:
						if ( ( $token == ';' ) && ( $this->mBraces == 0 ) ) {
							if ( !in_array( T_ABSTRACT, $this->mFunctionQualifiers ) ) {
								$this->error( $token );
							}
							// abstract function
							$this->mStatus = self::WAITING_FUNCTION;
							continue;
						}
						if ( $token == '{' ) {
							$this->mBraces++;
							if ( $this->mStatus == self::IN_FUNCTION_PARAMETERS )
								$this->mStatus = self::IN_FUNCTION;
						} elseif ( $token == '}' ) {
							$this->mBraces--;
							if ( $this->mInSwitch <= $this->mBraces )
								$this->mInSwitch = 0;

							if ( count( $this->mExtensionFunctions ) ) {
								foreach ( $this->mExtensionFunctions as $name => $level ) {
									if ( $level > $this->mBraces )
										unset( $this->mExtensionFunctions[$name] );
								}
							}

							$this->purgeGlobals();
							if ( ! $this->mBraces ) {
								if ( $this->mInProfilingFunction && $this->mAfterProfileOut & 1 ) {
									$this->warning( 'profileout', "Reached end of $this->mClass::$this->mFunction with last statement not being wfProfileOut" );
								}

								if ($this->mProfileStackIndex != 0) {
									$missingCalls = array();
									for ($i = $this->mProfileStackIndex; $i > 0; $i--) {
										$missingCalls[] = 'wfProfileOut(' . $this->mProfileStack[$i - 1]['args'] . ')';
									}

									if ( !in_array( $this->mMethod, self::$mMethodsSkippedProfileChecks ) ) {
										$this->warning( 'matchingprofiles', "Reached end of function $this->mFunction without calling " . implode( ', ', $missingCalls ) );
									}
								}
								$this->mProfileStackIndex = 0;

								$this->mStatus = self::WAITING_FUNCTION;
								$this->mFunctionQualifiers = array();
							}
							$this->mConditionalProfileOutCount = 0;
						} elseif ( $token == ';' && $this->mInProfilingFunction ) {
							// Check that there's just a return after wfProfileOut.
							if ( $this->mAfterProfileOut == 1 ) {
								$this->mAfterProfileOut = 2;
							} elseif ( $this->mAfterProfileOut == 2 ) {
								// Set to 3 in order to bail out at the return.
								// This way we don't complain about missing return in internal wfProfile sections.
								$this->mAfterProfileOut = 3;
							}
						} elseif ( $token == '@' ) {
							$this->warning( 'evil-@', "Use of @ operator in function {$this->mFunction}" );
						} elseif ( is_array ( $token ) ) {
							if ( $token[0] == T_GLOBAL ) {
								$this->mStatus = self::IN_GLOBAL;
								if ( $this->mInSwitch ) {
									$this->warning( 'global-in-switch', "Defining global variables inside a switch in line $token[2], function {$this->mFunction}" );
								}
							} elseif ( ( $token[0] == T_CURLY_OPEN ) || ( $token[0] == T_DOLLAR_OPEN_CURLY_BRACES ) ) {
								// {$ and ${ and  All these three end in }, so we need to open an extra brace to balance
								// T_STRING_VARNAME is documented as ${a but it's the text inside the braces
								$this->mBraces++;
							}
							if ( $lastMeaningfulToken == '!' ) {
								$token['negated'] = true;
								$currentToken = $token;
							}
							if ( $token[0] == T_STRING_VARNAME ) {
								$token[0] = T_VARIABLE;
								$token[1] = '$' . $token[1];
								$currentToken = $token;
							}
							if ( $token[0] == T_VARIABLE ) {
								# $this->debug( "Found variable $token[1]" );

								if ( ( $token[1] == '$this' ) && in_array( T_STATIC, $this->mFunctionQualifiers ) ) {
									$this->warning( 'this-in-static', "Use of \$this in static method function {$this->mFunction} in line $token[2]" );
								}

								if ( $lastMeaningfulToken[0] == T_PAAMAYIM_NEKUDOTAYIM ) {
									/* Class variable. No check for now */
								} elseif ( $lastMeaningfulToken[0] == T_STRING ) {
									$this->mLocalVariableTypes[ $token[1] ] = $lastMeaningfulToken[0];
								} else {
									if ( isset( $this->mFunctionGlobals[ $token[1] ] ) ) {
											$this->mFunctionGlobals[ $token[1] ][0] ++;
									} elseif ( $this->shouldBeGlobal( $token[1] ) ) {
										if ( $this->mStatus == self::IN_FUNCTION_PARAMETERS && $runningQueuedFunctions ) {
											// It will be a global passed in the use clause of the anonymous function
											$this->mFunctionGlobals[ $token[1] ] = array( 0, 0, $token[2] ); // Register as global
										} else {
											$this->warning( 'global-as-local', "{$token[1]} is used as local variable in line $token[2], function {$this->mFunction}" );
										}
									}
								}
							} elseif ( in_array( $token[0], self::$mExitTokens ) && $this->mInProfilingFunction ) {
								if ($this->mProfileStackIndex - $this->mConditionalProfileOutCount != 0) {
									$missingCalls = array();
									for ($i = $this->mProfileStackIndex - $this->mConditionalProfileOutCount; $i > 0; $i--) {
										$missingCalls[] = 'wfProfileOut(' . $this->mProfileStack[$i - 1]['args'] . ')';
									}
									$this->warning( $token[0] == T_THROW ? 'matchingprofiles-throw' : 'matchingprofiles', "$token[1] in line $token[2] without calling " . implode( ', ', $missingCalls ) );
								}
								$this->mConditionalProfileOutCount = 0;

								if ( $this->mAfterProfileOut == 2 ) {
									$this->mAfterProfileOut = 0;
								} else {
									$this->warning( $token[0] == T_THROW ? 'profileout-throw' : 'profileout', "$token[1] in line $token[2] is not preceded by wfProfileOut" );
								}
							} elseif ( $token[0] == T_FUNCTION ) {
								// We are already inside a function, so we must be entering an anonymous function
								$this->anonymousFunction = array( 1, "function __anonymous_function_line" . $token[2] );
								continue;
							} elseif ( $token[0] == T_SWITCH ) {
								if ( !$this->mInSwitch )
									$this->mInSwitch = $this->mBraces;
							} elseif ( ( $token[0] == T_PAAMAYIM_NEKUDOTAYIM ) && is_array( $lastMeaningfulToken ) && ( $lastMeaningfulToken[0] == T_VARIABLE ) ) {
								if ( ( $lastMeaningfulToken[1] == '$self' ) || ( $lastMeaningfulToken[1] == '$parent' ) ) {
									# Bug of r69904
									$this->warning( '$self', "$lastMeaningfulToken[1]:: used in line $lastMeaningfulToken[2] It probably should have been " . substr( $lastMeaningfulToken[1], 1 ) . "::" );
								}
							} elseif ( ( $token[0] == T_STRING ) && ( is_array( $lastMeaningfulToken )
									&& in_array( $lastMeaningfulToken[0], array( T_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM ) ) ) ) {
								# Class member or class constant
								$currentToken[0] = self::CLASS_MEMBER;
							} elseif ( $token[0] == T_STRING && is_array( $lastMeaningfulToken ) &&
								( in_array( $lastMeaningfulToken[0], array( T_INSTANCEOF, T_NEW ) ) ) ) {
								if ( interface_exists( $token[1], false ) ) {
									$currentToken[0] = self::INTERFACE_NAME;
								} else {
									$this->checkClassName( $token );
									$currentToken[0] = self::CLASS_NAME;
								}
							} elseif ( $token[0] == T_CONSTANT_ENCAPSED_STRING && is_array( $lastMeaningfulToken ) && $lastMeaningfulToken[1] == 'hideDeprecated()' ) {
								$this->mHiddenDeprecatedCalls[] = substr( $token[1], 1, -1 );
							} elseif ( in_array( $token[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ) ) ) {
								$this->mStatus = self::IN_FUNCTION_REQUIRE;
								$requirePath = '';
								continue;
							}
						}

						if ( self::isMeaningfulToken( $token ) && ( $lastMeaningfulToken[0] == T_THROW ) ) {
							if ( $token[0] == T_VARIABLE ) {
								// Probably rethrowing from a catch, skip
							} elseif ( $token[0] == T_NEW ) {
								// Correct, a new class instance
								// TODO: Verify it inherits from Exception
							} else {
								// We only want the last interpretation, see r77752
								// throw Exception; -> Exception is a constant
								// throw Exception("Foo"); -> Exception() is a function
								// throw new Exception("Foo"); -> Exception is a class.

								$this->warning( 'function-throw', "Not using new when throwing token {$token[1]} in line $token[2], function {$this->mFunction}" );
							}
						}

						/* Try to guess the class of the variable */
						if ( in_array( $token[0], array( T_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM ) ) ) {
							$currentToken['base'] = $lastMeaningfulToken;
						} else
						if ( ( ( $token[0] == T_STRING ) || ( $token[0] == self::CLASS_MEMBER ) )
							&& is_array( $lastMeaningfulToken ) && isset( $lastMeaningfulToken['base'] ) ) {
							$currentToken['base'] = $lastMeaningfulToken['base'];
							$currentToken['class'] = $this->guessClassName( $lastMeaningfulToken['base'] );
						}

						if ( ( $token == '(' ) && is_array( $lastMeaningfulToken ) ) {
							if ( $lastMeaningfulToken[0] == T_STRING ) {
								$lastMeaningfulToken[0] = self::FUNCTION_NAME;
								$this->checkDeprecation( $lastMeaningfulToken );
								$this->checkFunctionName( $lastMeaningfulToken );
								$this->mCallStack[] = array( 'function' => $lastMeaningfulToken, 'args' => '' );
								if ( $lastMeaningfulToken[1] == 'wfProfileIn' ) {
									$this->mInProfilingFunction = true;
									$this->mAfterProfileOut = 0;
								} elseif ( $lastMeaningfulToken[1] == 'wfProfileOut' ) {
									global $mwParentClasses;// echo "wfProfileOut $this->mClass " . ( isset( $mwParentClasses[ $this->mClass ] ) ? $mwParentClasses[ $this->mClass ] : "" ). "\n";
									if ( ( isset( $mwParentClasses[ $this->mClass ] ) && $mwParentClasses[ $this->mClass ] == 'ImageHandler' ) ||
										( $this->mClass == 'Hooks' && $this->mFunction == 'run' ) ) {
										// Do not treat as profiling any more. ImageHandler sons have profile sections just for their wfShellExec(). wfRunHooks profiles each hook.
										$this->mInProfilingFunction = false;
									} else {
										$this->mAfterProfileOut = 1;
									}
								}
							} else if ( $lastMeaningfulToken[0] == self::CLASS_MEMBER ) {
								$this->checkDeprecation( $lastMeaningfulToken );
								
								if ( $lastMeaningfulToken[1] == 'hideDeprecated' ) {
									// $this->hideDeprecated() used in tests to knowingly test a deprecated function.
									$lastMeaningfulToken[1] = 'hideDeprecated()';
								}
							}
						} elseif ( count( $this->mCallStack ) ) {
							if ( $token !== ')' ) {
								$this->mCallStack[ count( $this->mCallStack ) - 1 ]['args'] .= ( is_array( $token ) ? $token[1] : $token );
							} else { // $token == )
								$lastCall = array_pop( $this->mCallStack );
								$lastCall['braceLevel'] = $this->mBraces;

								/* Special processing for some functions */
								switch ( $lastCall['function'][1] ) {
									case 'wfProfileIn':
										$this->mProfileStack[$this->mProfileStackIndex++] = $lastCall;
										array_push( $this->mProfileStack, $lastCall );
										break;
									case 'wfProfileOut':
										$index = $this->mProfileStackIndex - $this->mConditionalProfileOutCount - 1;

										if ( $index < 0 ) { // Empty stack
											if ( !in_array( $this->mMethod, self::$mMethodsSkippedProfileChecks ) ) {
												$this->warning( 'matchingprofiles', "Call to wfProfileOut( {$lastCall['args']} ) in line $lastMeaningfulToken[2] without a previous wfProfileIn()" );
											}
										} else {
											$profilein = $this->mProfileStack[$index];

											if ( $profilein['args'] !== $lastCall['args'] ) {
												$profilingName = $profilein['args'];

												if ( !( $this->mMethod == 'Parser::braceSubstitution' && $lastCall['args'] == ' $titleProfileIn ' ) ) {
													$this->warning( 'matchingprofiles', "Call to wfProfileOut( {$lastCall['args']} ) in line $lastMeaningfulToken[2] but expecting wfProfileOut( $profilingName ) from wfProfileIn() of line " . $profilein['function'][2] );
												}
											}

											if ( $profilein['braceLevel'] < $this->mBraces ) {
												// Keep the ProfileIn in the stack
												$this->mConditionalProfileOutCount++;
											} else {
												if ( $this->mConditionalProfileOutCount ) {
													$this->warning( 'matchingprofiles', "Internal error in local variables for ConditionalProfileOut" );
													$this->mConditionalProfileOutCount = 0;
												}
												array_pop( $this->mProfileStack );
												$this->mProfileStackIndex--;
											}
										}
										break;

									case 'extension_loaded':
										/**
										 * Assumption: extension_loaded( foo ) will only be called inside a conditional.
										 * If negated, the conditional will contain a return or throw, in order to use 
										 * the extension in the rest of the function body.
										 * Else the extension will only be used inside the conditional.
										 */
										$extensionName = trim( $lastCall['args'], " \"'" );
										$level = isset( $lastCall['function']['negated'] ) && $lastCall['function']['negated'] ? $this->mBraces : $this->mBraces + 1;
										if ( isset( self::$extensionFunctions[$extensionName] ) ) {
											foreach ( self::$extensionFunctions[$extensionName] as $name ) {
												$this->mExtensionFunctions[$name] = $level;
											}
										}
										break;
								}

								// $this->debug( "Call to " . $lastCall['function'][1] . "(" . $lastCall['args'] . ")" );
							}
						}

						/* Detect constants */
						if ( self::isMeaningfulToken( $token ) && is_array( $lastMeaningfulToken ) &&
								( $lastMeaningfulToken[0] == T_STRING ) && !self::isPhpConstant( $lastMeaningfulToken[1] ) ) {

							if ( in_array( $token[0], array( T_PAAMAYIM_NEKUDOTAYIM, T_VARIABLE, T_INSTANCEOF ) ) ) {
								$this->checkClassName( $lastMeaningfulToken );
							} else {

								if ( !defined( $lastMeaningfulToken[1] ) && !in_array( $lastMeaningfulToken[1], $this->mConstants ) && !self::inIgnoreList( $lastMeaningfulToken[1], self::$constantIgnorePrefixes ) ) {
									$this->warning( 'undefined-constant', "Use of undefined constant $lastMeaningfulToken[1] in line $lastMeaningfulToken[2]" );
								}
							}
						}
						continue;

					case self::IN_GLOBAL:
						if ( $token == ',' )
							continue;
						if ( $token == ';' ) {
							$this->mStatus = self::IN_FUNCTION;
							continue;
						}
						if ( !self::isMeaningfulToken( $token ) )
							continue;

						if ( is_array( $token ) ) {
							if ( $token[0] == T_VARIABLE ) {
								if ( !$this->shouldBeGlobal( $token[1] ) && !$this->canBeGlobal( $token[1] ) ) {
									$this->warning( 'global-names', "Global variable {$token[1]} in line {$token[2]}, function {$this->mFunction} does not follow coding conventions" );
								}
								if ( isset( $this->mFunctionGlobals[ $token[1] ] ) ) {
									if ( !$this->mInSwitch ) {
										$this->warning( 'double-globals', $token[1] . " marked as global again in line {$token[2]}, function {$this->mFunction}" );
									}
								} else {
									$this->checkGlobalName( $token[1] );
									$this->mFunctionGlobals[ $token[1] ] = array( 0, $this->mBraces, $token[2] );
								}
								continue;
							}
						}
						$this->error( $token );

					case self::IN_INTERFACE:
						if ( $lastMeaningfulToken[0] == T_INTERFACE )
							$this->mKnownFileClasses[] = $token[1];

						if ( $token == '{' ) {
							$this->mBraces++;
						} elseif ( $token == '}' ) {
							$this->mBraces--;
							if ( !$this->mBraces )
								$this->mStatus = self::WAITING_FUNCTION;
						}
						continue;

					case self::IN_REQUIRE_WAITING:
					case self::IN_FUNCTION_REQUIRE:
						if ( $token == '{' ) {
							$this->mBraces++;
							$token = ';';
						}

						if ( $token == ';' ) {
							$requirePath = trim( $requirePath, ')("' );

							if ( substr( $requirePath, 0, 8 ) == "PHPUnit/" ) {
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}
							if ( $requirePath == "Testing/Selenium.php" ) {
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}
							if ( substr( $requirePath, 0, 12 ) == "Net/Gearman/" ) {
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}
							if ( substr( $requirePath, -18 ) == "/LocalSettings.php" ) {
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}
							if ( substr( $requirePath, -18 ) == "/StartProfiler.php" ) {
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}
							if ( strpos( $requirePath, '/wmf-config/' ) !== false ) {
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}
							if ( $requirePath == "Mail.php" || $requirePath == "Mail/mime.php" ) { # PEAR mail
								$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
								continue;
							}

							if ( ( $requirePath == '' ) || ( !file_exists( $requirePath ) && $requirePath[0] != '/' ) ) {
								/* Try prepending the script folder, for maintenance scripts (but see Maintenance.php:758) */
								$requirePath = dirname( $this->mFilename ) . "/" . $requirePath;
							}

							if ( !file_exists( $requirePath ) ) {
								if ( strpos( $requirePath, '$' ) === false ) {
									$this->warning( 'missing-requires', "Did not found the expected require of $requirePath" );
								}
							} else {
								$requirePath = realpath( $requirePath );
								if ( isset( self::$mRequireKnownClasses[$requirePath] ) ) {
									$this->mKnownFileClasses = array_merge( $this->mKnownFileClasses, self::$mRequireKnownClasses[$requirePath] );
									$this->mKnownFunctions = array_merge( $this->mKnownFunctions, self::$mRequireKnownFunctions[$requirePath] );
									$this->mConstants = array_merge( $this->mConstants, self::$mRequireKnownConstants[$requirePath] );
								} else {
									$newCheck = new CheckVars;
									$newCheck->load( $requirePath, false );
									$newCheck->execute();
									/* Get the classes defined there */
									$this->mKnownFileClasses = array_merge( $this->mKnownFileClasses, $newCheck->mKnownFileClasses );
									$this->mKnownFunctions = array_merge( $this->mKnownFunctions, $newCheck->mKnownFunctions );
									$this->mConstants = array_merge( $this->mConstants, $newCheck->mConstants );
									self::$mRequireKnownClasses[$requirePath] = $newCheck->mKnownFileClasses;
									self::$mRequireKnownFunctions[$requirePath] = $newCheck->mKnownFunctions;
									self::$mRequireKnownConstants[$requirePath] = $newCheck->mConstants;
								}
							}
							$this->mStatus = $this->mStatus - self::IN_REQUIRE_WAITING;
							continue;
						}

						if ( $token[0] == T_WHITESPACE )
							continue;

						if ( $token[0] == T_STRING_VARNAME ) {
							$token[0] = T_VARIABLE;
							$token[1] = '$' . $token[1];
							$currentToken = $token;
						}
						if ( $token[0] == T_VARIABLE && $this->mStatus == self::IN_FUNCTION_REQUIRE ) {
							if ( isset( $this->mFunctionGlobals[ $token[1] ] ) ) {
									$this->mFunctionGlobals[ $token[1] ][0] ++;
							} elseif ( $this->shouldBeGlobal( $token[1] ) ) {
								$this->warning( 'global-as-local', "{$token[1]} is used as local variable in line $token[2], function {$this->mFunction}" );
							}
						}
						if ( $token == '.' ) {
							if ( $requirePath == 'dirname(__FILE__)' || $requirePath == '__DIR__' ) {
								$requirePath = dirname( $this->mFilename );
							} elseif ( $requirePath == 'dirname(dirname(__FILE__))' || $requirePath == 'dirname(__DIR__)' ) {
								$requirePath = dirname( dirname( $this->mFilename ) );
							} elseif ( $requirePath == 'dirname(dirname(dirname(__FILE__)))' || $requirePath == 'dirname(dirname(__DIR__))' ) {
								$requirePath = dirname( dirname( dirname( $this->mFilename ) ) );
							}
						} else if ( $token[0] == T_CURLY_OPEN || $token == '}' ) {
							continue;
						} else if ( !is_array( $token ) ) {
							if ( $token == '(' && ( $requirePath == 'MWInit::compiledPath' || $requirePath == 'MWInit::interpretedPath' ) ) {
								$requirePath = "$IP/";
							} elseif ( ( $token != '(' ) || $requirePath != '' ) {
								$requirePath .= $token[0];
							}
						} else if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ) ) ) {
							$requirePath .= trim( $token[1], '\'"' );
						} else if ( $token[0] == T_VARIABLE ) {
							if ( $token[1] == '$IP' || $token[1] == '$mwPath' ) {
								$requirePath .= $IP;
							} elseif ( $token[1] == '$dir' ) {
								//  Scripts at phase3/maintenance/language/
								$requirePath .= dirname( $this->mFilename );
							} elseif ( $token[1] == '$wgStyleDirectory' ) {
								$requirePath .= "$IP/skins";
							} elseif ( in_array( $token[1], array( '$classFile', '$file', '$_fileName', '$fileName', '$filename' ) ) ) {
								/* Maintenance.php lines 374 and 894 */
								/* LocalisationCache.php, MessageCache.php, AutoLoader.php */
							} else {
								// $this->warning( "require uses unknown variable {$token[1]} in line {$token[2]}" );
								$requirePath .= $token[1];
							}
						} elseif ( $token[0] == T_STRING && $token[1] == 'RUN_MAINTENANCE_IF_MAIN' ) {
							$requirePath .= "$IP/maintenance/doMaintenance.php";
						} elseif ( $token[0] == T_STRING && $token[1] == 'MW_CONFIG_FILE' ) {
							$requirePath .= "$IP/LocalSettings.php";
						} else {
							$requirePath .= $token[1];
						}
						continue;

				}
			}

			if ( count( $this->queuedFunctions ) > 0 ) {
				$this->mTokens = token_get_all( "<?php " . array_shift( $this->queuedFunctions ) );
				$runningQueuedFunctions = true;
				continue;
			}
			break;
		} while (1);

		$this->checkPendingClasses();
		$this->checkPendingFunctions();
	}

	function checkDeprecation( $token ) {
		global $mwDeprecatedFunctions, $mwParentClasses;

		if ( $mwDeprecatedFunctions && !in_array( self::FUNCTION_DEPRECATED, $this->mFunctionQualifiers ) &&
			isset( $mwDeprecatedFunctions[ $token[1] ] ) ) {

			if ( isset( $token['class'] ) ) {
				$class = $token['class'];
				do {
					if ( in_array( $class, $mwDeprecatedFunctions[ $token[1] ] ) ) {
						$name = "{$token['class']}::{$token[1]}";
						
						if ( in_array( $name, $this->mHiddenDeprecatedCalls ) )
							$this->warning( 'hidden-deprecated-calls', "Non deprecated function $this->mFunction calls deprecated function $name in line {$token[2]} (hidden warning)" );
						else
							$this->warning( 'deprecated-calls', "Non deprecated function $this->mFunction calls deprecated function $name in line {$token[2]}" );
						return;
					}
					if ( !isset( $mwParentClasses[ $class ] ) ) {
						return;
					}
					$class = $mwParentClasses[ $class ];
				} while ( true );
			} else if ( isset( $token['base'] ) ) { # Avoid false positives for local functions, see maintenance/rebuildInterwiki.inc
				$this->warning( 'deprecated-might', "Non deprecated function $this->mFunction may be calling deprecated function " .
					implode( '/', $mwDeprecatedFunctions[ $token[1] ] ) . "::" . $token[1] . " in line {$token[2]}" );
			}
		}
	}

	function checkFunctionName( $token, $warn = 'defer' ) {
		if ( !isset( $token['base'] ) ) {
			// Local function

			if ( substr( $token[1], 0, 2 ) == 'wf' ) {
				// MediaWiki function
				// TODO: List them.
				return;
			}
			if ( $token[1] == 'dieout' && in_array( $this->mFunction, array( 'setup_database', 'initial_setup', 'setup_plpgsql' ) ) ) {
				return;
			}

			if ( isset( self::$poisonedFunctions[ strtolower( $token[1] ) ] ) ) {
				if ( $token[1] == 'var_dump' && ( substr( $this->mFunction, 0, 4 ) == 'dump' ) || basename( $this->mFilename ) == 'ApiFormatDump.php' ) {
					// Allow var_dump if the function purpose is really to dump contents
					return;
				}
				$this->warning( 'poisoned-function', "Poisoned function {$token[1]} called from {$this->mFunction} in line {$token[2]}: " . self::$poisonedFunctions[strtolower( $token[1] )] );
				return;
			}

			if ( function_exists( $token[1] ) ) {
				return;
			}

			if ( isset( $this->mExtensionFunctions[ $token[1] ] ) ) {
				if ( $this->mExtensionFunctions[ $token[1] ] > $this->mBraces ) {
					$this->warning( 'internal-error', "mExtensionFunctions contains entries for higher brace levels" );
				}
				return;
			}

			if ( in_array( $token[1], $this->mKnownFunctions ) ) {
				return;
			}

			if ( self::inIgnoreList( $token[1], self::$functionIgnorePrefixes ) ) {
				return;
			}

			if ( $warn == 'now' ) {
				foreach ( self::$extensionFunctions as $extensionName => $functions) {
					if ( in_array( $token[1], $functions ) ) {
						$this->warning( 'extension-not-loaded', "Function {$token[1]} called in line {$token[2]} belongs to extension $extensionName, but there was no check that $extensionName was available." );
						return;
					}
				}

				$this->warning( 'missing-function', "Unavailable function {$token[1]} in line {$token[2]}" );
			} else if ( $warn == 'defer' ) {
				// Defer to the end of the file
				$this->mUnknownFunctions[] = $token;
			}

		}
	}

	function checkPendingFunctions() {
		foreach ( $this->mUnknownFunctions as $functionToken ) {
			$this->checkFunctionName( $functionToken, 'now' );
		}
		$this->mUnknownFunctions = array();
	}

	/* Returns a class name, or null if it couldn't guess */
	function guessClassName( $token ) {
		static $wellKnownVars = array(
			'$wgArticle' => 'Article',
			'$wgTitle' => 'Title',
			'$wgParser' => 'Parser',
			'$wgUser' => 'User',
			'$wgOut' => 'OutputPage',
			'$wgRequest' => 'WebRequest',
			'$request' => 'WebRequest',
			'$wgMessageCache' => 'MessageCache',
			'$wgLang' => 'Language', '$wgContLang' => 'Language',
			'$dbw' => 'DatabaseBase', '$dbr' => 'DatabaseBase',
			'$sk' => 'Skin',
			'$wgMemc' => 'MWMemcached',
			'$thumb' => 'MediaTransformOutput',
			'$title' => 'Title', '$titleObj' => 'Title', '$desiredTitleObj' => 'Title',
			'$article' => 'Article', '$articleObj' => 'Article',
			'$rev' => 'Revision', '$revision' => 'Revision', 
			'$undoRev' => 'Revision', '$undoafterRev' => 'Revision',
			'$msg' => 'Message',
			'$stash' => 'UploadStash',
			'$handler' => 'ContentHandler',
		);
		static $wellKnownMembers = array(
			'db' => 'DatabaseBase', 'dbw' => 'DatabaseBase',
		);

		if ( $token[0] == T_VARIABLE ) {
			if ( isset( $wellKnownVars[ $token[1] ] ) ) {
				return $wellKnownVars[ $token[1] ];
			}
			if ( $token[1] == '$this' )
				return $this->mClass;
			
			if ( isset( $this->mLocalVariableTypes[$token[1]] ) )
				return $this->mLocalVariableTypes[$token[1]];
			
			$name = substr( $token[1], 1 );
		} elseif ( ( $token[0] == T_STRING ) || ( $token[0] == self::CLASS_MEMBER ) ) {
			if ( ( $token[1] == 'self' ) && !isset( $token['base'] ) )
				return $this->mClass;
			if ( ( $token[1] == 'parent' ) && !isset( $token['base'] ) )
				return $this->getParentName( $token );

			$name = $token[1];

			if ( isset( $wellKnownMembers[$name] ) )
				$name = $wellKnownMembers[$name];
			elseif ( $token[1][0] == 'm' )  // member
				$name = substr( $token[1], 1 );
		} else {
			return null;
		}
		$className = $this->checkClassName( array( 1 => ucfirst( $name ) ) , 'no' );
		if ( $className ) {
			return $className;
		}

		return null;
	}

	function error( $token ) {
		$msg = "Unexpected token " . ( is_string( $token ) ? $token : token_name( $token[0] ) ) ;
		if ( is_array( $token ) && isset( $token[2] ) ) {
			$msg .= " in line $token[2]";
		}
		$msg .= "\n";
		$this->warning( 'error', $msg );
		die( 1 );
	}

	function warning( $name, $msg ) {
		if ( !self::$enabledWarnings[$name] ) {
			return;
		}
		if ( !$this->mProblemCount ) {
			echo "Problems in {$this->mFilename}:\n";
		}
		$this->mProblemCount++;
		echo " $msg\n";
	}

	function foundProblems() {
		return $this->mProblemCount;
	}

	function debug( $msg ) {
		if ( $this->mDebug ) {
			echo "$msg\n";
		}
	}

	# Is this the name of a global variable?
	function shouldBeGlobal( $name ) {
		static $specialGlobals = array( '$IP', '$parserMemc', '$messageMemc', '$hackwhere', '$haveProctitle' );
		static $nonGlobals = array(	'$wgOptionalMessages', '$wgIgnoredMessages', '$wgEXIFMessages', # Used by Translate extension, read from maintenance/languages/messageTypes.inc
									'$wgMessageStructure', '$wgBlockComments' ); # Used by Translate extension and maintenance/language/writeMessagesArray.inc, read from maintenance/languages/messages.inc

		if ( $name == '$wgHooks' && $this->mClass == 'Installer' && $this->mFunction == 'includeExtensions' )
			return false;

		if ( basename( $this->mFilename ) == "thumb_handler.php" )
			return substr( $name, 0, 9 ) == '$thgThumb';

		return ( ( substr( $name, 0, 3 ) == '$wg' ) || ( substr( $name, 0, 3 ) == '$eg' ) || in_array( $name, $specialGlobals ) ) && !in_array( $name, $nonGlobals );
	}

	# Variables that can be used as global, but also as locals
	function canBeGlobal( $name ) {
		if ( $name == '$argv' ) {
			/* Used as global by maintenance scripts, but also common as function var */
			return true;
		}
		if ( isset( self::$mGlobalsPerFile[$name] ) && in_array( basename( $this->mFilename ) , self::$mGlobalsPerFile[$name] ) ) {
			return true;
		}
		if ( $this->mFunction == 'loadWikimediaSettings' ) {
			/* Skip the error about $site and $lang in Maintenance.php */
			return true;
		}

		return false;
	}

	private function purgeGlobals() {
		foreach ( $this->mFunctionGlobals as $globalName => $globalData ) {
			if ( $globalData[1] <= $this->mBraces )
				continue; # In scope

			#  global $x  still affects the variable after the end of the
			# conditional, but only if the condition was true.
			#  We keep in the safe side and only consider it defined inside
			# the if block (see r69883).

			if ( $globalData[0] == 0 ) {
				$this->warning( 'unused-global', "Unused global $globalName in function {$this->mFunction} line $globalData[2]" );
			}
			unset( $this->mFunctionGlobals[$globalName] );
		}
	}

	# Look for typos in the globals names
	protected function checkGlobalName( $name ) {
		if ( substr( $name, 0, 3 ) == '$wg' ) {
			if ( ( self::$mDefaultSettingsGlobals != null ) && !in_array( $name, self::$mDefaultSettingsGlobals ) ) {
				if ( !isset( self::$mGlobalsPerFile[$name] ) || !in_array( basename( $this->mFilename ) , self::$mGlobalsPerFile[$name] ) ) {
					$this->warning( 'undefined-global', "Global variable $name is not present in DefaultSettings" );
				}
			}
		}
	}

	static function isMeaningfulToken( $token ) {
		if ( is_array( $token ) ) {
			return ( $token[0] != T_WHITESPACE && $token[0] != T_COMMENT );
		} else {
			return strpos( '(&', $token ) === false ;
		}
	}

	# Constants defined by php
	static function isPhpConstant( $name ) {
		return in_array( $name, array( 'false', 'true', 'self', 'parent', 'null' ) );
	}

	/**
	 * @param array $token Token holding the class name
	 * @param string $warn  A value from 'no', 'defer', 'now¡
	 * @return mixed  The class name if it is found, false otherwise
	 */
	function checkClassName( $token, $warn = 'defer' ) {
		global $wgAutoloadLocalClasses;

		if ( $token[1] == 'self' )
			return $this->mClass;
		if ( $token[1] == 'parent' )
			return $this->getParentName( $token );

		if ( class_exists( $token[1], false ) ) return $token[1]; # Provided by an extension
		if ( substr( $token[1], 0, 8 ) == "PHPUnit_" ) return $token[1];
		if ( $token[1] == "Testing_Selenium" || $token[1] == "Testing_Selenium_Exception" ) return $token[1];
		if ( substr( $token[1], 0, 12 ) == "Net_Gearman_" ) return $token[1]; # phase3/maintenance/gearman/gearman.inc
		if ( $token[1] == "PEAR_Error" ) return $token[1]; # Services_JSON.php
		if ( $token[1] == "PHP_Timer" ) return $token[1]; # From PEAR, used in ParserHelpers.php

		if ( isset( self::$mExtraClassesPerFile[basename( $this->mFilename ) ] ) && in_array( $token[1], self::$mExtraClassesPerFile[basename( $this->mFilename ) ] )  )
			return $token[1];

		if ( !isset( $wgAutoloadLocalClasses[$token[1]] ) && !in_array( $token[1], $this->mKnownFileClasses ) ) {
			if ( $warn == 'now' ) {
				$this->warning( 'missing-class', "Use of unknown class $token[1] in line $token[2]" );
			} else if ( $warn == 'defer' ) {
				// Defer to the end of the file
				$this->mUnknownClasses[] = $token;
			} else {
				return false;
			}
		}
		return $token[1];
	}

	function checkPendingClasses() {
		foreach ( $this->mUnknownClasses as $classToken ) {
			$this->checkClassName( $classToken, 'now' );
		}
		$this->mUnknownClasses = array();
	}

	static function inIgnoreList( $name, $list ) {
		foreach ( $list as $prefix ) {
			if ( substr( $name, 0, strlen( $prefix ) ) == $prefix )
				return true;
		}
		return false;
	}

	function getParentName( $token ) {
		if ( !is_null( $this->mParent ) ) {
			return $this->mParent;
		}
		$this->warning( 'orphan-parent', "Use of parent in orphan class {$this->mClass} in line $token[2]" );
		return "-";
	}

	/**
	 * Sets a number of files which are considered as having always been
	 * loaded before any loaded one. Any functions/classes defined there
	 * will be assumed to be available.
	 */
	function preloadFiles( $files ) {
		$this->initVars();
		$this->mFilename = '__preload';
		$this->mTokens = array( T_OPEN_TAG, '<?php', 0 );

		for ( $i = 1; $i <= count( $files ); $i++ ) {
			$this->mTokens[] = array( T_REQUIRE, 'require', $i );
			$this->mTokens[] = array( T_CONSTANT_ENCAPSED_STRING, "'" . $files[$i - 1] . "'", $i );
			$this->mTokens[] = ';';
		}
		$this->execute();
		self::$mKnownFileClassesDefault = $this->mKnownFileClasses;
		self::$mKnownFunctionsDefault = $this->mKnownFunctions;
		self::$mConstantsDefault = $this->mConstants;
	}
}

if ( $argc < 2 ) {
	die (
"Usage:
	php $argv[0] [options] <PHP_source_file1> <PHP_source_file2> ...

Options:
	--generate-deprecated-list
	--generate-parent-list
	-Whelp : available warnings methods
	-W[no]key : disabled/enable key warning.
" );
}

$cv = new CheckVars();
// $cv->mDebug = true;
array_shift( $argv );
if ( $argv[0] == '--generate-deprecated-list' ) {
	$cv->setGenerateDeprecatedList( true );
	array_shift( $argv );
}
if ( $argv[0] == '--generate-parent-list' ) {
	$cv->setGenerateParentList( true );
	array_shift( $argv );
}

foreach ( $argv as $arg ) {
	if ( preg_match( '/^-W(no-)?(.*)/', $arg, $m ) ) {
		if ( $m[2] === 'help' ) {
			CheckVars::dumpWarningsKeywords();
			exit;
		} elseif ( !isset( CheckVars::$enabledWarnings[ $m[2] ] ) ) {
			var_dump( $m );
			die( "Wrong warning name $arg\n" );
		}
		CheckVars::$enabledWarnings[ $m[2] ] = strlen( $m[1] ) == 0;
	}
}

$failure = false;
$cv->preloadFiles( array( "$IP/includes/GlobalFunctions.php", "$IP/includes/normal/UtfNormalUtil.php" ) );

foreach ( $argv as $arg ) {
	if ( substr( $arg, 0, 2 ) == '-W' )
		continue;

	$cv->load( $arg );
	$cv->execute();

	if ( $cv->foundProblems() ) {
		$failure = true;
	}
}
if ( $cv->getGenerateDeprecatedList( ) ) {
	$cv->saveDeprecatedList( dirname( __FILE__ ) . "/deprecated.functions" );
}
if ( $cv->getGenerateParentList( ) ) {
	$cv->saveParentList( dirname( __FILE__ ) . "/parent.classes" );
}

if ( $failure ) {
	exit( 1 );
}
