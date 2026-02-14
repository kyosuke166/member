import mysql from 'mysql2/promise';

export async function getDbConnection() {
  return await mysql.createConnection({
    host: import.meta.env.DB_HOST,
    user: import.meta.env.DB_USER,
    password: import.meta.env.DB_PASS,
    database: import.meta.env.DB_NAME,
  });
}