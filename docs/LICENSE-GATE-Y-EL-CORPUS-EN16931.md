# El corpus EN 16931 y el gate de licencias — medido

**Estado: cerrado.** `.license-allowlist.yml` existe en la raíz. Pero el pendiente que este
documento describía —«296 ficheros EUPL bloqueados por el gate»— **era falso**, y merece quedar
escrito por qué, para que nadie vuelva a abrirlo.

## El gate nunca bloqueó esos ficheros. Dos razones independientes

Medido el 2026-09-17 **ejecutando el hook**, no leyéndolo:

```bash
touch tests/fixtures/en16931-1.3.16/Invoice-unit-UBL/BR-57.xml
git add tests/fixtures/en16931-1.3.16/Invoice-unit-UBL/BR-57.xml
bash scripts/pre-commit-license-gate.sh   # exit 0
```

**1. `EUPL` no está entre los patrones del gate.** Busca 19 nombres —MIT Licen[s]e, Apache Licen[s]e, GNU
GENERAL PUBLIC LICEN[S]E, L[G]PL, A[G]PL, Mozilla, M[P]L-2.0, BSD, ISC Licen[s]e,
Creative Comm[o]ns, CC-[B]Y, CC[0], Public Dom[a]in, Unlicen[s]e— y la European Union Public
Licence no está en la lista. (Los nombres llevan una letra entre corchetes A PROPÓSITO: un
documento que enumera lo que el gate busca se detecta a sí mismo. No los «limpies».)

```bash
grep -c "EUPL" scripts/pre-commit-license-gate.sh   # 0
```

**2. Aunque estuviera, el repo entero está exento.** `project_declared_oss_license()` lee la
cabecera del fichero PHP principal **desde HEAD** y encuentra `GPLv2 or later`;
`project_has_proprietary_declaration()` no encuentra ninguna declaración propietaria en
`composer.json`, `package.json` ni `readme.txt`. Con eso `PROJECT_IS_OSS=1` y el gate solo informa.

El diseño es deliberado y bueno: la declaración se lee de **HEAD**, nunca del contenido staged, así
que un commit no puede autorizarse a sí mismo metiendo una licencia OSS en la misma tanda.

## Por qué la allowlist existe igualmente

Es cinturón y tirantes. Documenta la excepción de forma explícita y sobrevive a que cambie
cualquiera de los dos mecanismos de arriba. Cuesta un fichero.

**El formato es un glob por línea, no YAML estructurado** — pese a la extensión `.yml`. Lo lee
`is_allowlisted()` con un `case "$path" in $line)`. La primera versión de este documento proponía
un YAML con `version:` y `allow:` con `path`/`reason`: habría sido leído como tres líneas sin
sentido y no habría eximido nada. Copiar el esquema de un fichero existente antes de escribir uno
nuevo es lo que lo evitó.

Falsificado poniendo un fichero con una frase de concesión real (la de Apache) dentro del
directorio eximido: sin la allowlist el gate lo lista como violación, con ella desaparece de la lista.

## Lo que el corpus es y por qué no se toca

`tests/fixtures/en16931-1.3.16/` es el banco de pruebas oficial del CEN/TC 434 bajo EUPL. Es el
falsificador del validador Schematron: `tools/en16931-schematron-conformance.php` tiene que seguir
dando **1129/1129** contra esos ficheros exactos. Editarlos para contentar a un gate destruiría la
única evidencia de que la traducción de XPath 2.0 a 1.0 es correcta. Además son material de prueba:
`.distignore` deja `tests/` fuera del paquete.

## Hallazgo abierto — el gate no conoce la EUPL

No es un problema de este repo, es un hueco del gate general: una licencia copyleft europea podría
entrar en un repositorio propietario sin disparar coincidencia en `LICENSE_PATTERNS` mientras `EUPL`
no figure en la lista de patrones. En este repositorio concreto existen 299 ficheros bajo EUPL
correspondientes al corpus oficial de validación EN 16931.

Añadir `EUPL` a `LICENSE_PATTERNS` es una decisión de política global sobre la herramienta de
verificación compartida, no una exención local de este repositorio, por lo que ese cambio queda fuera
del alcance de este plugin.
