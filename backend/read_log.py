import os
from pathlib import Path

def read_last_lines(file_path, n=50):
    try:
        with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
            lines = f.readlines()
            for line in lines[-n:]:
                print(line, end='')
    except Exception as e:
        print(f"Error reading file: {e}")

log_path = r"d:\proyectos\programasivan\Mail\data\logs\mail_manager.log"
print(f"Reading {log_path}...")
if os.path.exists(log_path):
    read_last_lines(log_path, 100)
else:
    print("Log file not found.")
