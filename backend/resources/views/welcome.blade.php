{{--
    Unica respuesta HTML de todo el backend, y a proposito es minima.

    Sustituye a la pagina de bienvenida del scaffold de Laravel, que traia un
    bloque <style> en linea. Con la politica de contenido de T11
    -`default-src 'none'`, sin excepciones- ese bloque quedaria bloqueado y la
    pagina se veria a medias. Habia dos salidas: abrir la politica con
    `'unsafe-inline'` para que el scaffold se viera bonito, o quitar el estilo
    en linea. Relajar la politica de TODA la aplicacion por una pagina que nadie
    usa habria sido justo el tipo de excepcion que despues nadie retira, asi que
    se quita el estilo.

    Sin CSS propio: los estilos por defecto del navegador bastan para tres
    parrafos y la CSP no los bloquea. No lleva scripts, ni imagenes, ni
    tipografias, ni enlaces a assets, de modo que la politica se cumple sin una
    sola excepcion.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GolsFintech — API</title>
</head>
<body>
    <h1>GolsFintech</h1>

    <p>
        Este servidor expone la API de la aplicacion. La interfaz del prospecto
        se sirve aparte y consume esta API bajo <code>/api/v1</code>.
    </p>

    <p>
        Los endpoints estan autenticados por omision. Las excepciones estan
        declaradas de forma explicita en <code>routes/api.php</code>, cada una
        con su justificacion.
    </p>

    <p>
        Estado del servicio: <code>/up</code>
    </p>
</body>
</html>
