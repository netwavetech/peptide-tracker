import pymysql

DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "db_user",
    "password": "user_pass",
    "database": "database_name",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
    "autocommit": False,
}