#!/usr/bin/env python3
"""Export a reviewed Signals client commit for the integration image, without local secrets."""
import argparse,json,subprocess,tarfile,tempfile
from pathlib import Path
parser=argparse.ArgumentParser()
parser.add_argument('--signals-client',type=Path,required=True)
parser.add_argument('--output',type=Path,required=True)
args=parser.parse_args()
if args.output.exists():raise SystemExit('Choose a new output directory; existing artifacts are never overwritten')
repos={'signals-client':args.signals_client.resolve()}
revisions={}
for label,repo in repos.items():
    if subprocess.check_output(['git','status','--porcelain'],cwd=repo,text=True).strip():raise SystemExit(f'{label} must be a clean, reviewed checkout')
    revisions[label]=subprocess.check_output(['git','rev-parse','HEAD'],cwd=repo,text=True).strip()
args.output.mkdir(parents=True,mode=0o700)
for label,repo in repos.items():
    destination=args.output/label;destination.mkdir()
    with tempfile.TemporaryFile() as archive:
        subprocess.run(['git','archive','--format=tar',revisions[label]],cwd=repo,stdout=archive,check=True)
        archive.seek(0)
        with tarfile.open(fileobj=archive) as source:
            for member in source.getmembers():
                parts=Path(member.name).parts
                if member.name.startswith('/') or '..' in parts or any(p in ['.git','vendor','node_modules'] or p.startswith('.env') for p in parts):
                    raise SystemExit(f'Unexpected development/private path in {label}: {member.name}')
            source.extractall(destination,filter='data')
(args.output/'revisions.json').write_text(json.dumps(revisions,indent=2)+'\n')
print(json.dumps(revisions))
