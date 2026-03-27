from app.auth import get_password_hash, verify_password

try:
    pwd = "admin"
    print(f"Hashing '{pwd}'...")
    hashed = get_password_hash(pwd)
    print(f"Hashed: {hashed}")
    
    print("Verifying...")
    valid = verify_password(pwd, hashed)
    print(f"Valid: {valid}")
except Exception as e:
    print(f"Error: {e}")
    import traceback
    traceback.print_exc()
