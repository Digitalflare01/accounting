"""
FastAPI Microservice for AI Financial Ingestion & Disambiguation
Accepts raw natural language strings from the Core PHP Backend and returns
standardized accounting structures with human-in-the-loop review flags.
"""

import os
from fastapi import FastAPI, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from typing import List, Optional
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

from gemini_parser import GeminiFinancialParser, ParsedTransactionResponse

app = FastAPI(
    title="Accounting AI Microservice",
    description="Microservice using Google Gemini API to parse financial strings into accounting ledgers with hallucination checks.",
    version="1.0.0"
)

# Enable CORS for PHP Backend and React Frontend
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Initialize Parser
parser = GeminiFinancialParser(api_key=os.getenv("GEMINI_API_KEY"))

# Request Schema
class ParseRequest(BaseModel):
    text: str = Field(..., example="Bought a Macbook for 1 lakh", description="Raw natural language transaction description")

class DepreciationRequest(BaseModel):
    prompt: str = Field(..., example="I bought a Macbook for 1 lakh on August 25, what is the depreciation?", description="Natural language prompt regarding asset purchase or depreciation query")

@app.get("/health")
async def health_check():
    """Liveness probe for core backend health checks"""
    return {
        "status": "healthy",
        "service": "accounting-ai-microservice",
        "gemini_client_active": parser.client is not None
    }

@app.post(
    "/calculate-depreciation",
    status_code=status.HTTP_200_OK,
    summary="Calculate statutory Indian depreciation from natural language prompt",
    description="Identifies asset category, checks 180-day rule, calculates WDV & SLM schedules, and provides journal entries."
)
async def calculate_depreciation(request: DepreciationRequest):
    if not request.prompt or not request.prompt.strip():
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Prompt text cannot be empty."
        )
    try:
        return parser.calculate_depreciation(request.prompt)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error resolving depreciation: {str(e)}"
        )

@app.post(
    "/parse-transaction",
    response_model=ParsedTransactionResponse,
    status_code=status.HTTP_200_OK,
    summary="Parse natural language financial text",
    description="Processes raw financial text, executes hallucination checks, and returns disambiguation flags when necessary."
)
async def parse_transaction(request: ParseRequest):
    if not request.text or not request.text.strip():
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Transaction text cannot be empty."
        )
    
    try:
        result = parser.parse_text(request.text)
        return result
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error parsing transaction: {str(e)}"
        )

# Alias for /parse
@app.post("/parse", response_model=ParsedTransactionResponse, status_code=status.HTTP_200_OK)
async def parse_alias(request: ParseRequest):
    return await parse_transaction(request)

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", 8001))
    host = os.getenv("HOST", "0.0.0.0")
    print(f"Starting Accounting AI Microservice on http://{host}:{port}")
    uvicorn.run("main:app", host=host, port=port, reload=True)
