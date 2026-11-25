<?php
/**
 * Evaluates the compiled EN 16931 rule set against a UBL document.
 *
 * This is a Schematron engine, not a Schematron processor: it evaluates the rule table that
 * tools/en16931-compile-schematron.php produces from the official artefact, with libxml's XPath 1.0.
 * It implements the part of the Schematron semantics the artefact uses — patterns, ordered rules,
 * first-match-per-node, assert and report — and nothing else.
 *
 * What it does NOT do, and never claims: it is not the reference implementation, it does not run the
 * official XSLT, and a document with no finding here can still be refused by an access point. The
 * result carries the artefact version so a reader can tell which release was applied.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchematronValidator {

	/** Findings above this are dropped: a document that produces more is broken, not diagnosable. */
	public const MAX_FINDINGS = 500;

	/** @var array{artefact:array<string,string>, namespaces:array<string,string>, patterns:array<int,array<string,mixed>>} */
	private array $ruleset;

	/**
	 * @param array<string, mixed>|null $ruleset Injectable so a test can drive a small table.
	 */
	public function __construct( ?array $ruleset = null ) {
		/** @var array{artefact:array<string,string>, namespaces:array<string,string>, patterns:array<int,array<string,mixed>>} $table */
		$table         = $ruleset ?? require __DIR__ . '/En16931RuleSet.php';
		$this->ruleset = $table;
	}

	/** The artefact this instance evaluates: name, version, release date, source, licence. */
	public function artefact(): array {
		return $this->ruleset['artefact'];
	}

	/**
	 * How many assertions the table holds and how many of them this engine evaluates.
	 *
	 * A rule the compiler could not translate stays in the table carrying `unsupported`; it is named
	 * here rather than quietly skipped, because a rule that disappears reads as a rule that passed.
	 *
	 * @return array{total:int, evaluated:int, unsupported:array<int, array{id:string, reason:string}>, fatal:int, business:int}
	 */
	public function coverage(): array {
		$total       = 0;
		$fatal       = 0;
		$business    = 0;
		$unsupported = [];

		foreach ( $this->ruleset['patterns'] as $pattern ) {
			foreach ( $pattern['rules'] as $rule ) {
				foreach ( $rule['assertions'] as $assertion ) {
					$total++;
					if ( 'fatal' === $assertion['flag'] ) {
						$fatal++;
					}
					if ( ! str_starts_with( $assertion['id'], 'UBL-' ) ) {
						$business++;
					}
					if ( isset( $assertion['unsupported'] ) ) {
						$unsupported[] = [ 'id' => $assertion['id'], 'reason' => (string) $assertion['unsupported'] ];
					}
				}
			}
		}

		return [
			'total'       => $total,
			'evaluated'   => $total - count( $unsupported ),
			'unsupported' => $unsupported,
			'fatal'       => $fatal,
			'business'    => $business,
		];
	}

	/**
	 * Run the rule set over an XML string.
	 *
	 * @return array{artefact:array<string,string>, well_formed:bool, findings:array<int, array{id:string, flag:string, message:string, location:string}>, fatal:int, warning:int, evaluated:int, unevaluated:array<int,string>, truncated:bool}|\WP_Error
	 */
	public function validate_xml( string $xml ) {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new \WP_Error(
				'wm_peppol_schematron_not_xml',
				__( 'Die Datei ist kein wohlgeformtes XML; vor der Regelprüfung ist nichts zu prüfen.', 'wm-peppol-as4-gateway' ),
				[ 'status' => 400 ]
			);
		}

		return $this->validate_document( $document );
	}

	/**
	 * Run the rule set over a parsed document.
	 *
	 * @return array{artefact:array<string,string>, well_formed:bool, findings:array<int, array{id:string, flag:string, message:string, location:string}>, fatal:int, warning:int, evaluated:int, unevaluated:array<int,string>, truncated:bool}
	 */
	public function validate_document( \DOMDocument $document ): array {
		$xpath = new \DOMXPath( $document );
		foreach ( $this->ruleset['namespaces'] as $prefix => $uri ) {
			$xpath->registerNamespace( $prefix, $uri );
		}

		$findings    = [];
		$unevaluated = [];
		$evaluated   = 0;
		$truncated   = false;

		foreach ( $this->ruleset['patterns'] as $pattern ) {
			// Schematron fires the FIRST rule of a pattern whose context matches a node; a node a
			// previous rule already claimed is not offered to the next one.
			$claimed = new \SplObjectStorage();

			foreach ( $pattern['rules'] as $rule ) {
				$contexts = $this->select( $xpath, (string) $rule['context'], null );
				if ( null === $contexts ) {
					continue;
				}

				foreach ( $contexts as $node ) {
					if ( isset( $claimed[ $node ] ) ) {
						continue;
					}
					$claimed[ $node ] = true;

					foreach ( $rule['assertions'] as $assertion ) {
						if ( isset( $assertion['unsupported'] ) ) {
							continue;
						}

						$evaluated++;
						$holds = $this->evaluate( $xpath, $node, $assertion );

						if ( null === $holds ) {
							$unevaluated[] = (string) $assertion['id'];
							continue;
						}

						// An assert fires when its test is false; a report fires when it is true.
						$fires = 'report' === $assertion['type'] ? $holds : ! $holds;
						if ( ! $fires ) {
							continue;
						}

						if ( count( $findings ) >= self::MAX_FINDINGS ) {
							$truncated = true;
							break 4;
						}

						$findings[] = [
							'id'       => (string) $assertion['id'],
							'flag'     => (string) $assertion['flag'],
							'message'  => (string) $assertion['message'],
							'location' => $node->getNodePath() ?? '',
						];
					}
				}
			}
		}

		return [
			'artefact'    => $this->artefact(),
			'well_formed' => true,
			'findings'    => $findings,
			'fatal'       => count( array_filter( $findings, static fn( array $f ): bool => 'fatal' === $f['flag'] ) ),
			'warning'     => count( array_filter( $findings, static fn( array $f ): bool => 'fatal' !== $f['flag'] ) ),
			'evaluated'   => $evaluated,
			'unevaluated' => array_values( array_unique( $unevaluated ) ),
			'truncated'   => $truncated,
		];
	}

	/**
	 * One assertion against one context node, with its bound variables substituted first.
	 */
	private function evaluate( \DOMXPath $xpath, \DOMNode $node, array $assertion ): ?bool {
		$test = (string) $assertion['test'];

		foreach ( (array) ( $assertion['bind'] ?? [] ) as $name => $sequence ) {
			[ $value, $usable ] = $this->query( $xpath, (string) $sequence, $node );

			if ( ! $usable ) {
				return null;
			}

			// The sequence is a node list ("cbc:TaxCurrencyCode") or already a scalar, because the
			// compiler turns a cast into number(): reading only node lists made every rule of the
			// BR-S-08 family pass vacuously, which the official corpus caught.
			if ( $value instanceof \DOMNodeList ) {
				$literal = $value->length > 0 ? trim( (string) $value->item( 0 )->nodeValue ) : null;
			} elseif ( is_float( $value ) ) {
				$literal = is_nan( $value ) ? null : (string) $value;
			} elseif ( is_string( $value ) ) {
				$literal = '' === trim( $value ) ? null : trim( $value );
			} else {
				$literal = null;
			}

			// "every $v in () satisfies P" is true over an empty sequence: the rule has nothing to
			// object to, which is not the same as the rule failing.
			if ( null === $literal ) {
				return true;
			}

			$test = str_replace(
				'$' . $name,
				is_numeric( $literal ) ? $literal : "'" . str_replace( "'", "''", $literal ) . "'",
				$test
			);
		}

		[ $result, $usable ] = $this->query( $xpath, $test, $node );

		// An expression this engine cannot evaluate must not read as a satisfied rule. It is reported
		// as a finding of its own so a translation defect is visible instead of silently green.
		if ( ! $usable ) {
			return null;
		}

		if ( $result instanceof \DOMNodeList ) {
			return $result->length > 0;
		}

		return (bool) $result;
	}

	/**
	 * Context nodes for an expression, or null when libxml refuses it.
	 *
	 * A refused expression is not a passing rule and not a failing one. It is counted nowhere and
	 * reported by tools/en16931-schematron-conformance.php, which is where a translation defect has
	 * to show up.
	 */
	private function select( \DOMXPath $xpath, string $expression, ?\DOMNode $node ): ?\DOMNodeList {
		[ $result, $usable ] = $this->query( $xpath, $expression, $node );

		return $usable && $result instanceof \DOMNodeList ? $result : null;
	}

	/**
	 * Evaluate an expression, telling "the test is false" apart from "libxml refused the expression".
	 *
	 * DOMXPath::evaluate() answers false for both, and conflating them is the defect that would make
	 * this whole engine useless: every violated assertion would read as one it could not evaluate, and
	 * an invalid document would come back clean. The libxml error buffer is what separates them
	 * (measured: a false test leaves 0 errors, a malformed expression leaves 1).
	 *
	 * @return array{0:\DOMNodeList|string|float|bool|null, 1:bool} The value, and whether it is usable.
	 */
	private function query( \DOMXPath $xpath, string $expression, ?\DOMNode $node ): array {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$result = null === $node ? @$xpath->evaluate( $expression ) : @$xpath->evaluate( $expression, $node ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an expression libxml refuses is handled here, not by a warning.

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return [ $result, [] === $errors ];
	}
}
