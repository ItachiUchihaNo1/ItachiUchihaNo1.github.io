from pathlib import Path
import json,subprocess,sys
scratch=Path(sys.argv[1]);php=sys.argv[2];jobs=json.loads((scratch/'membership28-race.json').read_text());worker=Path(__file__).with_name('membership28_race_worker.php');cmd=[php,'-c',str(scratch/'php.ini'),str(worker),str(scratch/'private-config.php')]
processes=[subprocess.Popen(cmd,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in jobs]
for process,job in zip(processes,jobs):process.stdin.write(json.dumps(job).encode());process.stdin.close()
results=[]
for process in processes:
 body=process.stdout.read();error=process.stderr.read();assert process.wait(timeout=20)==0,error.decode();results.append(json.loads(body))
assert sum(bool(r.get('ok')) for r in results)==1,results
assert sum(r.get('code')=='CREDIT_EXHAUSTED' for r in results)==1,results
proof=subprocess.run(cmd,input=json.dumps({'operation':'verify','user_id':jobs[0]['actor']['user_id']}).encode(),capture_output=True,check=True);assert json.loads(proof.stdout)=={'bookings':1,'holds':1},proof.stdout
print('PASS two simultaneous consultation requests cannot exceed one available credit; one booking and one hold persisted')
