#!/usr/bin/env python3
"""Run one database lane of the currently advertised acceptance profiles."""
import argparse
from pathlib import Path
import subprocess
import sys
HERE=Path(__file__).resolve().parent
PROFILES=[('7.1.2','php82','redis'),('7.0.1','php82','default'),('7.1.2','php74','redis'),('7.1.2','php80','redis'),('7.1.2','php81','redis')]
if __name__=='__main__':
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('engine',choices=['mysql','mariadb']);p.add_argument('--php-only',action='store_true');a=p.parse_args()
 for wp,php,cache in PROFILES:
  if a.php_only and php=='php82': continue
  subprocess.run([sys.executable,str(HERE/'run-exact-125.py'),'--wp',wp,'--php',php,'--db',a.engine,'--cache',cache],check=True)
