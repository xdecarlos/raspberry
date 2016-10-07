import random

acuario = random.uniform(24.0, 27.0)
ambiente = random.uniform(19.0, 28.0)

import MySQLdb
db = MySQLdb.connect("localhost","root","","ACUARIO")
cursor = db.cursor()
cursor.execute("""INSERT INTO temps (temp1,temp2) VALUES (%s,%s) """,(acuario,ambiente))
db.commit()
