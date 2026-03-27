"""
Script de diagnóstico para probar la conexión IMAP.
Ayuda a identificar problemas de conexión con el servidor de correo.
"""
import imaplib
import ssl
import sys
from getpass import getpass


def test_imap_connection(host: str, port: int, username: str, password: str, use_ssl: bool = True):
    """
    Prueba la conexión IMAP con diferentes configuraciones.
    """
    print(f"\n{'='*60}")
    print(f"Probando conexión IMAP")
    print(f"{'='*60}")
    print(f"Host: {host}")
    print(f"Port: {port}")
    print(f"Username: {username}")
    print(f"SSL: {use_ssl}")
    print(f"{'='*60}\n")
    
    connection = None
    
    try:
        # Paso 1: Conectar al servidor
        print("Paso 1: Conectando al servidor...")
        if use_ssl:
            try:
                connection = imaplib.IMAP4_SSL(host, port)
                print("✓ Conexión SSL establecida correctamente")
            except ssl.SSLError as e:
                print(f"✗ Error SSL: {e}")
                print("\nIntentando sin verificación SSL...")
                # Crear contexto SSL sin verificación
                ssl_context = ssl.create_default_context()
                ssl_context.check_hostname = False
                ssl_context.verify_mode = ssl.CERT_NONE
                connection = imaplib.IMAP4_SSL(host, port, ssl_context=ssl_context)
                print("✓ Conexión SSL (sin verificación) establecida")
        else:
            connection = imaplib.IMAP4(host, port)
            print("✓ Conexión sin SSL establecida")
        
        # Paso 2: Autenticación
        print("\nPaso 2: Autenticando...")
        try:
            result = connection.login(username, password)
            print(f"✓ Autenticación exitosa: {result}")
        except imaplib.IMAP4.error as e:
            print(f"✗ Error de autenticación: {e}")
            print("\nPosibles causas:")
            print("  - Contraseña incorrecta")
            print("  - Usuario incorrecto")
            print("  - Requiere contraseña de aplicación (Gmail, Outlook)")
            print("  - IMAP no habilitado en la cuenta")
            return False
        
        # Paso 3: Listar carpetas
        print("\nPaso 3: Listando carpetas...")
        try:
            status, folders = connection.list()
            if status == 'OK':
                print(f"✓ Carpetas encontradas: {len(folders)}")
                print("\nPrimeras 5 carpetas:")
                for folder in folders[:5]:
                    print(f"  - {folder.decode()}")
            else:
                print(f"✗ Error al listar carpetas: {status}")
        except Exception as e:
            print(f"✗ Error al listar carpetas: {e}")
        
        # Paso 4: Seleccionar INBOX
        print("\nPaso 4: Seleccionando INBOX...")
        try:
            status, data = connection.select('INBOX')
            if status == 'OK':
                num_messages = int(data[0])
                print(f"✓ INBOX seleccionado correctamente")
                print(f"  Mensajes en INBOX: {num_messages}")
            else:
                print(f"✗ Error al seleccionar INBOX: {status}")
        except Exception as e:
            print(f"✗ Error al seleccionar INBOX: {e}")
        
        # Paso 5: Buscar mensajes
        print("\nPaso 5: Buscando mensajes...")
        try:
            status, data = connection.search(None, 'ALL')
            if status == 'OK':
                message_ids = data[0].split()
                print(f"✓ Búsqueda exitosa")
                print(f"  IDs de mensajes encontrados: {len(message_ids)}")
                if message_ids:
                    print(f"  Primeros 5 IDs: {message_ids[:5]}")
            else:
                print(f"✗ Error en búsqueda: {status}")
        except Exception as e:
            print(f"✗ Error en búsqueda: {e}")
        
        print(f"\n{'='*60}")
        print("✓ DIAGNÓSTICO COMPLETADO EXITOSAMENTE")
        print(f"{'='*60}\n")
        return True
        
    except ConnectionRefusedError:
        print(f"\n✗ ERROR: Conexión rechazada")
        print("Posibles causas:")
        print("  - El servidor IMAP no está disponible")
        print("  - El puerto está bloqueado por firewall")
        print("  - Host o puerto incorrectos")
        return False
        
    except socket.gaierror:
        print(f"\n✗ ERROR: No se puede resolver el host '{host}'")
        print("Posibles causas:")
        print("  - Host incorrecto")
        print("  - Sin conexión a internet")
        return False
        
    except socket.timeout:
        print(f"\n✗ ERROR: Timeout de conexión")
        print("Posibles causas:")
        print("  - Servidor IMAP no responde")
        print("  - Firewall bloqueando la conexión")
        return False
        
    except Exception as e:
        print(f"\n✗ ERROR INESPERADO: {type(e).__name__}")
        print(f"Detalles: {e}")
        import traceback
        print("\nTraceback completo:")
        traceback.print_exc()
        return False
        
    finally:
        if connection:
            try:
                connection.logout()
                print("\n✓ Desconectado correctamente")
            except:
                pass


def main():
    """Función principal del script de diagnóstico."""
    print("\n" + "="*60)
    print("DIAGNÓSTICO DE CONEXIÓN IMAP")
    print("="*60)
    
    # Configuraciones predefinidas comunes
    presets = {
        "1": ("Gmail", "imap.gmail.com", 993),
        "2": ("Outlook/Hotmail", "outlook.office365.com", 993),
        "3": ("Yahoo", "imap.mail.yahoo.com", 993),
        "4": ("iCloud", "imap.mail.me.com", 993),
        "5": ("Custom", None, None)
    }
    
    print("\nSelecciona el proveedor de email:")
    for key, (name, _, _) in presets.items():
        print(f"  {key}. {name}")
    
    choice = input("\nOpción (1-5): ").strip()
    
    if choice in presets:
        name, host, port = presets[choice]
        if choice == "5":
            host = input("Host IMAP: ").strip()
            port = int(input("Puerto IMAP (normalmente 993): ").strip())
        else:
            print(f"\nUsando configuración de {name}:")
            print(f"  Host: {host}")
            print(f"  Puerto: {port}")
    else:
        print("Opción inválida")
        return
    
    username = input("\nUsuario/Email: ").strip()
    password = getpass("Contraseña: ")
    
    # Ejecutar diagnóstico
    success = test_imap_connection(host, port, username, password)
    
    if success:
        print("\n✓ La conexión IMAP funciona correctamente")
        print("  Puedes usar esta configuración en la aplicación")
    else:
        print("\n✗ La conexión IMAP falló")
        print("  Revisa los errores anteriores para más detalles")


if __name__ == "__main__":
    import socket
    main()
