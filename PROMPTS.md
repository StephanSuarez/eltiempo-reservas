# PROMPTS

Herramienta: Claude Code (Opus 5.5).
Cada prompt aparece exactamente como fue enviado al asistente de desarrollo.
Los borradores los escribí y revisé con apoyo de GPT-6 Astra (ChatGPT) antes de enviarlos (para detectar ambigüedades y contexto faltante); la decisión final del texto es mía.
Se omiten las confirmaciones triviales ("sí", "continúa").

---

## Prompt 1: Análisis y plan (sin código)

```text
# OBJETIVO

Quiero diseñar una API REST en PHP 8.2+ y MySQL 8+ para administrar reservas de inventario.

En esta etapa no quiero implementación. Primero quiero que analices el problema y propongas un plan técnico. Voy a revisar y discutir ese plan antes de autorizar la implementación.

# CONTEXTO

La API debe permitir crear una reserva mediante:

POST /reservations

Request:

{
"request_id": "REQ-2026-0001",
"product_id": 1,
"quantity": 3
}

Una respuesta exitosa debe permitir conocer:

{
"reservation_id": 15,
"status": "confirmed",
"remaining_stock": 7
}

Reglas funcionales:

1. Una reserva solamente puede realizarse cuando existe inventario suficiente.

2. El stock nunca puede quedar por debajo de 0.

3. request_id identifica de manera única una solicitud.

4. Si se recibe nuevamente un request_id que ya fue procesado correctamente, se debe devolver la reserva previamente creada y el inventario no puede descontarse nuevamente.

5. La idempotencia no debe depender exclusivamente de una validación realizada desde PHP. La base de datos también debe garantizar que no puedan existir dos reservas asociadas al mismo request_id.

6. La API debe manejar solicitudes concurrentes garantizando que dos solicitudes no puedan reservar las mismas unidades de inventario. La validación y actualización del stock debe ser segura ante concurrencia, evitando que el stock quede negativo o se produzca sobreventa.

Caso crítico:

Stock inicial = 1.

Llegan prácticamente al mismo tiempo dos solicitudes diferentes:

Solicitud A:
quantity = 1

Solicitud B:
quantity = 1

El resultado debe ser:

- una reserva confirmada;
- una reserva rechazada por stock insuficiente;
- stock final = 0;
- nunca debe existir sobreventa.

7. Se deben contemplar como mínimo:

- producto inexistente;
- quantity igual o menor que 0;
- stock insuficiente;
- request_id duplicado;
- datos obligatorios faltantes.

# RESTRICCIONES

- PHP 8.5.
- MySQL 8.4 LTS.
- API HTTP/REST.
- Usar Composer para la gestión de dependencias.
- Todo el entorno debe ejecutarse mediante Docker Compose.
- Tanto la API PHP como MySQL deben ejecutarse en contenedores.
- Usar versiones específicas de las imágenes Docker; no usar `latest`.
- El directorio actual es la raíz del repositorio.
- No crear una carpeta contenedora adicional como `api/` o `app/` para alojar todo el proyecto.
- No crear un sistema complejo de excepciones.
- Evitar componentes o abstracciones que no sean necesarios para cumplir los requisitos.
- No implementar todavía.
- No crear ni modificar archivos en esta etapa.
- No asumir decisiones técnicas importantes que no estén definidas. Proponlas y justifícalas para que pueda revisarlas antes de implementar.
- No generes specs, documentación extensa ni tareas de Jira. En esta etapa entrega únicamente el plan técnico solicitado.
- El servidor HTTP utilizado debe permitir procesar solicitudes en paralelo, de forma que la prueba de concurrencia represente concurrencia real.
- No incluir credenciales, contraseñas, tokens ni archivos `.env` con información sensible en Git. Usar `.env` local y versionar únicamente `.env.example` sin secretos.
- El modelo de datos debe incluir como mínimo `products` (`id`, `name`, `stock`) y `reservations` (`id`, `request_id`, `product_id`, `quantity`, `created_at`). Puede ampliarse únicamente cuando exista una justificación técnica.

# ENTREGABLE

Quiero un plan técnico que explique:

1. Arquitectura propuesta.
2. Estructura del proyecto.
3. Modelo de datos propuesto.
4. Flujo completo para crear una reserva.
5. Manejo de validaciones y errores.
6. Estrategia de idempotencia.
7. Estrategia de concurrencia.
8. Manejo de transacciones e integridad de datos.
9. Riesgos técnicos que identificas antes de implementar.
10. Orden en el que implementarías la solución.

Para las decisiones técnicas importantes, explica brevemente:

- qué propones;
- por qué;
- qué problema resuelve.

No escribas código todavía.

# VERIFICACIÓN POSTERIOR

Después de implementar, quiero verificar como mínimo:

1. Reserva correcta.
2. Stock insuficiente.
3. Idempotencia de request_id.
4. Concurrencia.

Quiero que estas condiciones puedan verificarse mediante pruebas automatizadas.

Además, para el escenario crítico de concurrencia quiero un script reproducible que ejecute dos requests HTTP en paralelo contra POST /reservations y permita comprobar:

- una reserva confirmada;
- una reserva rechazada;
- stock final = 0.

En esta etapa solamente incluye en el plan cómo realizarías estas verificaciones. No implementes todavía las pruebas ni el script.

Antes de presentar el plan, dime en 3 líneas:

1. Qué entendiste.
2. Cuál identificas como el principal riesgo técnico.
3. Qué NO vas a hacer en esta etapa.
```

**Resultado breve:** plan sin código: Apache prefork + PHP sin framework + PDO, UPDATE condicional atómico para el stock, UNIQUE(request_id) + manejo del error 1062 para idempotencia, CHECK en BD, tests de integración HTTP y script con curl_multi.

**Mi revisión:** acepté el plan y respondí las decisiones abiertas en el prompt 2.

---

## Prompt 2: Aprobación del plan y decisiones técnicas

```text
Apruebo el plan. Puedes implementar siguiendo el orden de la sección 10, con estas decisiones:

# DECISIONES

1. Servidor: Apache prefork con php:8.5-apache.
2. Framework: ninguno.
3. D1: opción (a). Guardar remaining_stock en reservations para que un reintento devuelva exactamente la misma respuesta.
4. D2: 201 al crear la reserva y 200 en un reintento con el mismo request_id.
5. D3: si llega un request_id existente con otro payload, devolver la reserva existente sin comparar los datos.
6. D4: base de datos de test separada, con la misma estructura (mismo schema.sql), reiniciada antes de cada test, para que los tests no afecten la base de desarrollo. Propón cómo apunta la API a esa base durante los tests con la menor configuración posible (por ejemplo, una segunda base en el mismo contenedor MySQL) y explícamelo antes de implementarlo.
7. Tipos: validación estricta; solo enteros JSON.
8. Códigos: 422 para errores de validación y 400 solo para JSON inválido.
9. Deadlock: no implementes reintento automático; responde 503 como indica el plan. Lo auditaremos más adelante.
10. El script de concurrencia corre contra la base de desarrollo.

# README

Escribe un README breve y puntual con:

- una línea que explique qué es la API;
- cómo levantar la API y la base de datos con Docker (la base se crea al levantar el entorno; indica cómo reiniciarla);
- un ejemplo de curl para consumir POST /reservations;
- el comando para ejecutar las pruebas y el script de concurrencia.

Deja una sección "Decisiones técnicas" vacía; la escribo yo.

# RESTRICCIONES

- No modifiques ni elimines PROMPTS.md.
- Verifica en Docker Hub las etiquetas exactas de las imágenes antes de fijarlas.
- Al terminar, ejecuta las pruebas y el script de concurrencia y muéstrame la salida.
```

**Resultado breve:** implementó el entorno (imágenes verificadas: php:8.5.11-apache-trixie, mysql:8.4.11, composer:2.10.3), el esquema, el endpoint y la transacción. Lo verificó a mano con curl: validaciones, idempotencia, stock 1 con dos requests en paralelo (1 confirmada, 1 rechazada, stock 0) y 50 requests contra stock 6 (6 confirmadas). Corrigió por su cuenta la collation de request_id a utf8mb4_0900_bin (utf8mb4_bin
ignora espacios finales). Para D4 propuso dos opciones: A (vhost de test) o B (contenedor api-test).

**Mi revisión:** rechacé A y B y elegí tests que llaman la clase directamente contra la base de test (prompt 3). Queda pendiente para la auditoría: la IA no pudo confirmar si los reintentos concurrentes pasaron por el UNIQUE (error 1062) o por la búsqueda previa en PHP.

---

## Prompt 3: BD de test y puerto de MySQL

```text
No apruebo A ni B. Prefiero una opción más simple:

- Mantén db/test-db.sh (crea la base _test en el mismo MySQL con el mismo usuario) y el reinicio con db/schema.sql antes de cada test.
- Los tests no pasan por HTTP: llaman directamente a Reservations con una conexión PDO a la base de test, configurada en phpunit.xml. No agregues vhost ni contenedor extra.
- El test de concurrencia debe ser real: dos procesos PHP separados, cada uno con su propia conexión, reservando al mismo tiempo sobre stock 1.
- La capa HTTP (routing, validación y códigos) queda demostrada con el script de concurrencia contra desarrollo y el curl del README.

Motivo: menos infraestructura y tests más cercanos a pruebas unitarias.

Además, como hay que hacer docker compose down -v: publica el puerto de MySQL solo en 127.0.0.1, configurable desde .env con 3306 por defecto (agrégalo a .env.example), para poder conectarme con un cliente gráfico.

Con esto, continúa con los tests, el script de concurrencia y el README.
```

**Resultado breve:** 6 tests PHPUnit pasando (reserva correcta, stock insuficiente, producto inexistente, idempotencia y dos de concurrencia con procesos PHP separados). Script de concurrencia OK en 3 corridas. MySQL publicado en 127.0.0.1. README con la sección de decisiones vacía.

**Mi revisión:** la IA introdujo errores a propósito para comprobar que los tests los detectan. Al quitar el manejo del request_id duplicado, el test falla: la idempotencia la garantiza la base de datos (UNIQUE), no solo la búsqueda previa en PHP. Esto resuelve el pendiente del prompt 2.

---

## Prompt 4: Auditoría

Enviado con modo ultracode (revisión multiagente).

```text
Audita la solución completa buscando problemas de:
1. concurrencia;
2. transacciones;
3. idempotencia;
4. integridad de datos.

Incluye el caso de deadlock sin reintento automático que dejamos pendiente.

Para cada hallazgo indica: qué es, un escenario concreto donde falla, severidad y la corrección que propones. No modifiques código todavía: voy a decidir qué recomendaciones acepto y cuáles rechazo.
```

**Resultado breve:** la auditoría corrió experimentos reales y encontró 7 hallazgos y 3 menores. No hay sobreventa ni datos corruptos. El hallazgo principal (H1): un reintento concurrente con stock justo respondía 409 aunque la reserva existía.

**Mi revisión:** ver la tabla de revisión crítica.

| #   | Recomendación de la IA | Decisión  | Motivo |
| --- | ---------------------- | --------- | ------ |
| H1 | Buscar de nuevo el request_id si el UPDATE no afecta filas | Aceptada | Viola la regla de idempotencia; los tests no lo detectaban porque usaban stock 5 |
| H2 | Bajar innodb_lock_wait_timeout de 50 s a 5 s | Aceptada | Una transacción colgada bloqueaba el producto 50 s y podía agotar Apache |
| H3 | Mantener 503 sin reintento automático | Aceptada | El deadlock solo ocurre en un caso extremo; el rollback es completo y reintentar es seguro |
| H4 | Sacar los DROP de schema.sql | Aceptada | Evita borrar la base de desarrollo por error |
| H5 | Documentar reintento ante 500/503 | Aceptada | Un 500 no garantiza que la reserva no exista |
| H6 | Ajustar límites de conexiones Apache/MySQL | Rechazada | Con H2 corregido es muy improbable |
| H7 | Validar quantity dentro de Reservations | Rechazada | La base ya lo protege con CHECK; por HTTP no se puede llegar |
| Menores | 1062 de otra clave, DATETIME, solapamiento de tests | Rechazadas | No ocurren en la práctica (id autoincremental) o no afectan esta prueba |
| D3 | (reabierta por mí) 409 si el request_id llega con otros datos | Cambiada | La auditoría demostró que un cliente recibía la reserva de otro producto |

---


## Prompt 5: Revisión crítica

Decisiones sobre la auditoría. Implementa solo lo aceptado:
```text
- H1 aceptada: si el UPDATE afecta 0 filas, busca de nuevo el request_id y devuelve 200 si la reserva existe. Agrega el test concurrente con stock 1.
- H2 aceptada: innodb_lock_wait_timeout = 5 en la conexión.
- H3 aceptada: se mantiene 503 sin reintento automático.
- H4 aceptada: mueve los DROP de schema.sql al setUp de los tests.
- H5 aceptada: documenta en el README que ante 500 o 503 se reintenta con el mismo request_id.
- D3 reabierta: si el request_id existe con otro product_id o quantity, responde 409 request_id_conflict. Agrega su test y actualiza la tabla de errores del README.
- H6, H7 y menores rechazados.

No modifiques PROMPTS.md. Al terminar, corre los tests y el script de concurrencia y muéstrame la salida.
```
**Resultado breve:** de los hallazgos de la auditoría se aplicaron H1, H2, H4 y D3 en código y H5 en el README; H3 confirmó mantener el 503 sin reintento automático. H1 corrigió un error real de idempotencia (un reintento concurrente recibía 409 aunque la reserva existía). H2 bajó la espera por locks de 50 s a 5 s. Quedaron 8 tests en verde, y la IA comprobó que el test nuevo de H1 falla si se quita la corrección.

**Mi revisión:** acepté los cambios que corrigen errores reales o evitan casos borde con impacto (locks colgados, borrado accidental de la base, respuestas incorrectas en reintentos) y rechacé los que no aportan en este contexto (H6, H7 y menores). El test de reintento con payload distinto se dividió porque D3 cambió ese comportamiento a propósito. Acepto el costo de H2: ante un bloqueo externo, las reservas fallan rápido con 503 en lugar de esperar, y reintentar es seguro gracias a la idempotencia.

---

## Prompt 6: Revisión de seguridad

```text
Revisa la seguridad de la solución completa buscando problemas de:
1. inyección SQL;
2. validación de entradas;
3. exposición de información en errores;
4. manejo de secretos y configuración;
5. exposición de puertos y contenedores;
6. abuso o denegación de servicio.
7. otras vulnerabilidades que consideres pertinentes.

Para cada hallazgo indica: qué es, un escenario concreto donde falla, severidad y la corrección que propones. No modifiques código todavía: voy a decidir qué recomendaciones acepto y cuáles rechazo.

Restricción: No me entregues vulnerabilidades que supongas las vulneravilidades deben ser verificadas que si sean una vulnerabilidad, o si no, no lo son
```

**Resultado breve:** 5 vulnerabilidades verificadas; sin inyección SQL ni fuga de secretos. Solo V1 (JSON grande agota la RAM) y V2 (API abierta a la red) tenían impacto real.

**Mi revisión:** acepté V1 (los cuerpos válidos miden menos de 1 KB) y V2 (proteger a quien lo ejecute en una red compartida). Rechacé V3–V5: fuera de alcance o no explotables por sí solas.

### Decisiones de seguridad

| #  | Recomendación de la IA | Decisión | Motivo |
| -- | ---------------------- | -------- | ------ |
| V1 | Limitar el cuerpo a 4096 bytes | Aceptada | Un JSON de 8 MB tumbaba el proceso y con 30 en paralelo el contenedor llegó a ~4 GB de RAM; la corrección es una línea |
| V2 | Publicar la API solo en 127.0.0.1 | Aceptada (configurable) | Quien ejecute el repo en una red compartida no expone su API; queda configurable para probar desde otro equipo |
| V3 | Autenticación | Rechazada | Fuera del alcance del enunciado |
| V4 | Proteger contra conexiones lentas | Rechazada | Con la API en 127.0.0.1 no hay atacante de red; en producción iría detrás de un proxy |
| V5 | Ocultar versiones de Apache y PHP | Rechazada | Por sí sola no es explotable |

---

## Prompt 7: Correcciones de seguridad

```text
Decisiones sobre la revisión de seguridad:

- V1 aceptada: limita el cuerpo de las solicitudes a 4096 bytes en Apache.
- V2 aceptada: publica la API solo en 127.0.0.1 por defecto, configurable desde .env (agrégalo a .env.example) para poder probar desde otro equipo de mi red.
- V3, V4 y V5 rechazadas.

Después de aplicarlas, repite los experimentos de V1 y V2 y verifica que quedaron corregidas. No modifiques PROMPTS.md. Al terminar, corre los tests y el script de concurrencia y muéstrame la salida.
```

**Resultado breve:** V1 y V2 aplicadas y verificadas repitiendo los experimentos: un cuerpo de 8 MB ahora recibe 413 y la RAM no pasa de 20 MiB; la API ya no responde por la IP de red (se abre con `API_HOST=0.0.0.0`). 8 tests y script de concurrencia en verde.

**Mi revisión:** acepto el efecto secundario de V1: si el cuerpo supera el límite, la respuesta 413 sale con formato mixto (HTML + JSON), pero ningún cliente legítimo envía más de 1 KB. La IA también agregó la fila 413 al README sin pedírselo; la mantengo porque documenta el nuevo comportamiento.